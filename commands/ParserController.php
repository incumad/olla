<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use yii\console\Controller;
use app\components\ParseJE;
use app\components\ParseNR;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class ParserController extends Controller
{
    /**
     * This command echoes what you have entered as the message.
     * @param string $message the message to be echoed.
     */
    public function actionIndex()
    {
        $oParseJEComponent = new ParseJE();
        
        /*
        $oParseJEComponent = \Yii::createObject([
            'class' => ParseJE(),
            'prop1' => 3,
            'prop2' => 4,
        ], [1, 2]);
         * */
        
        $oParseJEComponent->parse();
    }
}