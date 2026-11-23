<?php

namespace App\Modules\LibraryProtection\Exceptions;

use RuntimeException;

/** La página no se pudo marcar: el lector no la entrega (falla cerrado). */
class WatermarkUnavailable extends RuntimeException {}
