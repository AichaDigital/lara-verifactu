<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests\Fixtures\Activation;

use AichaDigital\LaraVerifactu\Services\AeatClient;
use Closure;
use ReflectionProperty;
use SoapClient;

/**
 * The SOAP transport, doubled BELOW the client's activation guard (AID-1907).
 *
 * Injected into a real AeatClient, so the guard, the core and the response
 * parsing are the production code; only the wire is fake.
 */
final class FakeSoapTransport extends SoapClient
{
    public int $calls = 0;

    /**
     * @param  (Closure(int): mixed)|null  $onCall  runs on each call with the call number; may throw
     */
    public function __construct(private readonly ?Closure $onCall = null)
    {
        parent::__construct(null, ['location' => 'https://aeat.invalid/soap', 'uri' => 'urn:fake']);
    }

    public function __soapCall(string $name, array $args, ?array $options = null, $inputHeaders = null, &$outputHeaders = null): mixed
    {
        $this->calls++;

        if ($this->onCall !== null) {
            ($this->onCall)($this->calls);
        }

        return (object) ['EstadoEnvio' => 'Correcto', 'CSV' => 'CSV-FAKE-' . $this->calls];
    }

    public function __getLastRequest(): ?string
    {
        return null;
    }

    public function __getLastResponse(): ?string
    {
        return null;
    }

    /**
     * A real AeatClient whose wire is this transport.
     */
    public static function clientWith(self $transport): AeatClient
    {
        $client = new AeatClient('https://aeat.invalid/soap', 5, false);

        (new ReflectionProperty(AeatClient::class, 'client'))->setValue($client, $transport);

        return $client;
    }
}
