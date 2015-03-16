<?php

namespace app\components;

use yii\base\Object;
use yii\db\Query;

class ParseNR extends Object
{
    private $_db;
    
    const SOURCE = 2;  
    
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
        
        ini_set("memory_limit","512M");
    }
    
    public function parse()
    {   
        echo 'MU:' . memory_get_usage() . "\n";
        
        //$this->_parseSitemap();
        $dataReader = $this->_db->createCommand('SELECT * FROM url_aux WHERE  url  not in (select url from restaurant) ')->query();
        
        $count = 0;
        
        while ($row = $dataReader->read()){
            
            ++$count;
            
            if ($count > 0) {
            
                echo $row['url'] . ':' . $count .  ' MU:' . memory_get_usage() . "\n";

                $this->_processParams($this->_parseRestaurant($row['url']));
            
            }
            
        }
    }
    
    private function _parseSitemap() {
        // hay que instanciarlo con el "namespace" la \, sino no funciona
        $xmlDoc = new \DOMDocument();
        $file = \Yii::$app->basePath . '/protected/data/sitemap.xml';
        $xmlDoc->load($file);
        
        $sitemapUrls = $xmlDoc->getElementsByTagName("url"); 

        $count = 0;
        foreach ($sitemapUrls as $sitemapUrl) { 
            $url = $sitemapUrl->getElementsByTagName("loc")->item(0)->nodeValue;
            $priority = $sitemapUrl->getElementsByTagName("priority")->item(0)->nodeValue;
            if ($priority == '0.7') {
                
                $this->_db->createCommand()->insert('url_aux', [
                    'url' => $url
                ])->execute();
                
                
                echo $url . ' : ' . ($count++) . "\n";
                //$this->_processParams($this->_parseRestaurant($url));
            }
        }        
        
    }
    
    private function _parseRestaurant($url) {
        $params = array();
        $rs = \SimpleHtmlDom\file_get_html_wt($url);
        
        $html = $rs[0];
        $txt = $rs[1];
        sleep(rand(1,2));
        
        if (empty($html)) {
            return $params;
        }        
      
        $find1 = '.total_opiniones, h2.restauranteTitle, .address span.tooltip, .address meta';

        $count = 0;
        $paramNames = ['name','address','reviews'];
        $params['reviews'] = 0;
        $params['postal_code'] = '';
        $params['address'] = '';
        
        foreach($html->find($find1) as $elementContent) {
            
            if (!empty($elementContent->content)) {
                $params['postal_code'] = $elementContent->content;
            } else {
                if (!empty($elementContent->tooltip)) {
                    $str = trim(html_entity_decode(preg_replace ( '/\s\s+/', ' ', trim ( $elementContent->tooltip ) )));
                } else {
                    $str = trim(html_entity_decode(preg_replace ( '/\s\s+/', ' ', trim ( $elementContent->plaintext ) )));
                }
                $params[$paramNames[$count]] = $str;
                $count ++;
            }
            
        }
        
        $find2 = '.type span';
        
        foreach($html->find($find2) as $elementContent) {
            if (!empty($elementContent->itemprop) && ($elementContent->itemprop == 'servesCuisine')) {
                $params['type'][] = trim(html_entity_decode(preg_replace ( '/\s\s+/', ' ', trim ( $elementContent->plaintext) )));
            }
        }        
        
        preg_match('/\((.*)\)/', $params['address'], $matches);
        
        if (isset($matches[1])) {
            $params['city'] = $matches[1];
        } else {
            return array();
        }
        
        
        $patron = ",telefono:'(.*?)'";
        preg_match("/$patron/ism", $txt, $match);
        
        if (isset($match[1])) {
            $params['extra'] = str_replace('Teléfono: ','',$match[1]);
        } else {
            $params['extra'] = '';
        }
        
        $params['url'] = $url;
        
        print_r($params);

        return $params;
        
    }
    
    private function _processParams($params) {
        
        if (empty($params)) {
            return;
        }
        
        // compruebo si existe la ciudad
        $city = $this->checkCity($params);
        
        // compruebo si existen los tipos de comida
        $aType = $this->checkFoodType($params);
        
        
        // Inserto el restaurante
        $rs = $this->_db->createCommand('INSERT INTO restaurant (name, url, address, postal_code, reviews, source_id, extra,city_id) VALUES (:name, :url, :address, :postal_code, :reviews, ' . ParseNR::SOURCE  . ', :extra, :city_id)')
                            ->bindValue(':name', $params['name'])
                            ->bindValue(':url', $params['url'])
                            ->bindValue(':address', $params['address'])
                            ->bindValue(':postal_code', $params['postal_code'])
                            ->bindValue(':reviews', $params['reviews'])
                            ->bindValue(':extra', $params['extra'])
                            ->bindValue(':city_id', $city['id'])
                            ->execute();
        
        if ($rs) {
            $restaurantId = $this->_db->getLastInsertID();
            // creo la relación entre tipos de comida y restaurantes
            $aInsert = [];
            foreach($aType as $type) {
                $aInsert[] = [$type['id'],$restaurantId];
            }
            $this->_db->createCommand()->batchInsert('food_type_restaurant', ['food_type_id', 'restaurant_id'], $aInsert )->execute();
        }
    }
    
    private function checkCity($params){
        
        $city = $this->_db->createCommand('SELECT * FROM city where name = :city')
                ->bindValue(':city', $params['city'])
                ->queryOne();
        
        if (empty($city)) {
            $this->_db->createCommand('INSERT INTO city (name, source_id) VALUES (:name, ' . ParseNR::SOURCE  . ' )')
                    ->bindValue(':name', $params['city'])
                    ->execute();  
            
            $city['name'] = $params['city'];
            $city['id'] = $this->_db->getLastInsertID();
            
        }
        
        return $city;
    }
    
    private function checkFoodType($params){
        $aType = array();
        
        foreach ($params['type'] as $paramType) {
            $type = $this->_db->createCommand('SELECT * FROM food_type where name = :type')
                    ->bindValue(':type', $paramType)
                    ->queryOne();

            if (empty($type)) {
                $this->_db->createCommand('INSERT INTO food_type (name, source_id) VALUES (:name, ' . ParseNR::SOURCE  . ' )')
                        ->bindValue(':name', $paramType)
                        ->execute();  

                $type['name'] = $paramType;
                $type['id'] = $this->_db->getLastInsertID();

            }
            
            $aType[] = $type; 
        }
        
        return $aType;
    }    
    
}


