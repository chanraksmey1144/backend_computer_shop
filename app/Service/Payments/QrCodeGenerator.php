<?php

namespace App\Service\Payments;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeGenerator
{
    public function generateDataUri(string $data, int $size = 360): string
    {
        $qrCode = new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 16,
        );

        $result = (new SvgWriter())->write($qrCode);

        return $result->getDataUri();
    }
}
