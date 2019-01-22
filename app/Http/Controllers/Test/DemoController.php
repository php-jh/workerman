<?php
/**
 * Created by PhpStorm.
 * User: ${蒋华}
 * Date: 2019/1/21
 * Time: 11:09
 */

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Controller;
use PFinal\Idempotent\Idempotent;
class DemoController extends Controller
{
    public function demo(){
        $seq = 'c4ca4238a0b923820dcc509a6f758492';

        Idempotent::$config['db.config'] = [
            'dsn' => 'mysql:host=127.0.0.1;dbname=test',
            'username' => 'root',
            'password' => 'root',
            'charset' => 'utf8',
            'tablePrefix' => '',
        ];
        $result = Idempotent::run($seq, function () {
            //你自己的业务
            return time();
        });

//同一个$seq多次请求，将得到相同的结果
        echo $result;
    }



}