<?php
/**
 * PHP QR Code encoder (LGPL 3)
 *
 * 拆分自合并版单文件：每个类独立成文件，框架自动加载（Qrcode\类名）。
 * 版权与协议见同目录 LICENSE。
 *
 * @package Qrcode
 */

namespace QRcode;

require_once __DIR__ . '/Constants.class.php';

    class QRrs {
    
        public static $items = array();
        
        //----------------------------------------------------------------------
        public static function init_rs($symsize, $gfpoly, $fcr, $prim, $nroots, $pad)
        {
            foreach(self::$items as $rs) {
                if($rs->pad != $pad)       continue;
                if($rs->nroots != $nroots) continue;
                if($rs->mm != $symsize)    continue;
                if($rs->gfpoly != $gfpoly) continue;
                if($rs->fcr != $fcr)       continue;
                if($rs->prim != $prim)     continue;

                return $rs;
            }

            $rs = QRrsItem::init_rs_char($symsize, $gfpoly, $fcr, $prim, $nroots, $pad);
            array_unshift(self::$items, $rs);

            return $rs;
        }
    }

