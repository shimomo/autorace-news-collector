<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use RuntimeException;

/**
 * HTTP の失敗（非 2xx、トランスポート失敗、JSON 復号失敗）。symfony の例外は外へ出さない。
 *
 * @author shimomo
 */
final class HttpException extends RuntimeException
{
}
