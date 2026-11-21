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

    class QRrsblock {
        public $dataLength;
        public $data = array();
        public $eccLength;
        public $ecc = array();
        
        public function __construct($dl, $data, $el, &$ecc, QRrsItem $rs)
        {
            $rs->encode_rs_char($data, $ecc);
        
            $this->dataLength = $dl;
            $this->data = $data;
            $this->eccLength = $el;
            $this->ecc = $ecc;
        }
    };

