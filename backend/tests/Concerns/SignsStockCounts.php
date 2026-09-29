<?php

namespace Tests\Concerns;

/** A stock controller's sign-off payload, as the signature pad sends it. */
trait SignsStockCounts
{
    /** A real 1×1 PNG, so the stored signature embeds in the PDF. */
    protected const SIGNATURE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @return array{signature: string, name: string, device: string} */
    protected function signOff(string $name = 'Mike Controller'): array
    {
        return ['signature' => self::SIGNATURE_PNG, 'name' => $name, 'device' => 'PHPUnit'];
    }
}
