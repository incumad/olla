<?php

namespace app\components;

use yii\base\Object;

class ParseJE extends Object
{
    private $_db;
    
    const SOURCE = 1;  
    
    public $force;

    public function __construct()
    {
        // ... initialization before configuration is applied
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        
        $path = \Yii::$app->basePath . '/protected/data/parser.sqlite';
        
        $this->_db = new \yii\db\Connection([
            'dsn' => 'sqlite:' .  $path,
            'charset' => 'utf8'
        ]);     
    }
    
    public function parse()
    {
        //$this->_parseFoodType();
        //$this->_parseCityFoodType();
        //$this->_parseRestaurants();
        $this->_importFromAuxTables();
    }
    
    
    private function _importFromAuxTables() {
        
        //update restaurant_aux_je set city_id = (select city_id from restaurant where restaurant.postal_code = restaurant_aux_je.postal_code)
        
        $dataReader = $this->_db->createCommand('SELECT name,url,address,postal_code,reviews,rating,city_id, GROUP_CONCAT( food_type) as food_types
    FROM (
    SELECT name,url,address,postal_code,reviews,rating,city_id, (SELECT food_type_id FROM city_food_type WHERE city_food_type.id = city_food_type_id) food_type
    FROM restaurant_aux_je WHERE city_id IS NOT null GROUP BY name, food_type ) calc
    GROUP BY name')->query();
        
        
        
        $count = 0;
        
        while ($params = $dataReader->read()){
            
            ++$count;
            
            echo $count . "\n";
            
            if ($count > 0) {
                $foodTypes = explode(',',$params['food_types']);
                
                $this->_db->createCommand('INSERT INTO restaurant (name, url, address, postal_code, reviews,rating, source_id,city_id) VALUES 
                    (:name, :url, :address, :postal_code, :reviews,:rating, ' . ParseJE::SOURCE  . ', :city_id)')
                    ->bindValue(':name', $params['name'])
                    ->bindValue(':url', $params['url'])
                    ->bindValue(':address', $params['address'])
                    ->bindValue(':postal_code', $params['postal_code'])
                    ->bindValue(':reviews', $params['reviews'])
                    ->bindValue(':rating', $params['rating'])
                    ->bindValue(':city_id', $params['city_id'])
                    ->execute();                
                
                $restaurantId = $this->_db->getLastInsertID();

                $aInsert = [];
                foreach($foodTypes as $type) {
                    $aInsert[] = [$type,$restaurantId];
                }
                $this->_db->createCommand()->batchInsert('food_type_restaurant', ['food_type_id', 'restaurant_id'], $aInsert )->execute();
                
            }
            
        }        
        
    }
    
    private function _parseFoodType()
    {
        $url = 'http://www.just-eat.es/adomicilio/tipos-de-comida/';
        
        $html = \SimpleHtmlDom\file_get_html($url);
        
        $command = $this->_db->createCommand('INSERT INTO food_type (name, url, source_id) VALUES (:name,:url,' . ParseJE::SOURCE . ')');
        
        foreach($html->find('.cuisine a') as $element) { 
            
            $name = trim($element->plaintext);
            $url = trim($element->href);

            $command->bindValue(':name', $name)
                    ->bindValue(':url', $url)
                    ->execute();
        }
        
    }
    
    private function _parseCityFoodType() {
        $aCities = array();
        
        $foodTypes = $this->_db->createCommand('SELECT * FROM food_type')
            ->queryAll();
        
        $commandCity = $this->_db->createCommand('INSERT INTO city (id,name) VALUES (:id,:name)');
        
        foreach($foodTypes as $foodType) {
                    
            echo $foodType['url'] . "\n";        
            
            $html = \SimpleHtmlDom\file_get_html($foodType['url']);
            sleep(rand(1,3)); // stop some second to dont up the warnings

            $commandCityFood = $this->_db->createCommand('INSERT INTO city_food_type (name, url, source_id, food_type_id, city_id) VALUES (:name,:url,' . ParseJE::SOURCE . ', ' . $foodType['id'] . ',:city_id)');

            foreach($html->find('.locations-cuisines a') as $element) { 
                
                $city = trim($element->plaintext);
                
                if (!isset($aCities[$city])) { // inserto en el array de ciudades
                    $idCity = count($aCities) + 1;
                    $aCities[$city] = $idCity;
                    $commandCity->bindValue(':name', $city)
                            ->bindValue(':id', $idCity)
                            ->execute();
                } else {
                    $idCity = $aCities[$city];
                }                 
                
                $name = $city . ' ' . $foodType['name'];
                $url = trim($element->href);

                $commandCityFood->bindValue(':name', $name)
                        ->bindValue(':url', $url)
                        ->bindValue(':city_id', $idCity)
                        ->execute();
            }
        }
    }
    
    private function _parseRestaurants() {
        $cityFoodTypes = $this->_db->createCommand('SELECT * FROM city_food_type')
            ->queryAll();
        
        
        $commandRestaurant = $this->_db->createCommand('INSERT INTO restaurant (name, url, address, postal_code, reviews, rating, city_food_type_id) VALUES (:name, :url, :address, :postal_code, :reviews, :rating, :city_food_type_id)'); 
        $num = 0;
        foreach($cityFoodTypes as $cityFoodType) {
            
            echo $cityFoodType['url'] . ' : ' . ($num++) . "\n";
            
            $html = \SimpleHtmlDom\file_get_html($cityFoodType['url']);
            sleep(rand(1,3)); // stop some second to dont up the warnings

                    
            foreach($html->find('.restaurantWithLogo') as $element) {
                $param = array();
                $count = 0;
                foreach($element->find('h3 a, address, .extLink') as $elementContent) {
                    
                    $str = trim(html_entity_decode(preg_replace ( '/\s\s+/', ' ', trim ( $elementContent->plaintext ) )));
                    
                    if($count == 0) {
                      $param['name'] = $str;  
                      $param['url'] = $elementContent->href;  
                    } elseif ($count == 1) {
                      $param['address'] = $str;
                      $param['postal_code'] = array_pop(explode(' ',$str));
                    } elseif ($count == 2) {
                        $str = str_replace('opiniones', '', $str);
                        $str = str_replace('opinión', '', $str);
                        $param['reviews'] = $str;
                    } elseif ($count == 3) {
                        $str = str_replace('rating-', '', $str);
                        $param['rating'] = $str;
                    }

                    $count ++;
                }
                
                //Insertamos 

                $commandRestaurant->bindValue(':name', $param['name'])
                        ->bindValue(':url', $param['url'])
                        ->bindValue(':address', $param['address'])
                        ->bindValue(':postal_code', $param['postal_code'])
                        ->bindValue(':reviews', $param['reviews'])
                        ->bindValue(':rating', $param['rating'])
                        ->bindValue(':city_food_type_id', $cityFoodType['id'])
                        ->execute();
            } 
        }
        
    }
    
    
}


