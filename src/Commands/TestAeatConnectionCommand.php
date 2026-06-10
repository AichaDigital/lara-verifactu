<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

use AichaDigital\LaraVerifactu\Contracts\EndpointResolverContract;
use AichaDigital\LaraVerifactu\Services\CertificateManager;
use Illuminate\Console\Command;

final class TestAeatConnectionCommand extends Command
{
    protected $signature = 'verifactu:test-connection
                            {--cert-info : Show certificate information only}';

    protected $description = 'Test AEAT connection and certificate validation';

    public function handle(): int
    {
        $this->info('🔐 Testing AEAT Connection & Certificate');
        $this->newLine();

        // Check configuration
        if (! $this->checkConfiguration()) {
            return self::FAILURE;
        }

        // Test certificate
        if (! $this->testCertificate()) {
            return self::FAILURE;
        }

        // If --cert-info, stop here
        if ($this->option('cert-info')) {
            return self::SUCCESS;
        }

        // Test SOAP connection
        if (! $this->testSoapConnection()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ All tests passed successfully!');

        return self::SUCCESS;
    }

    /**
     * Check if configuration is complete
     */
    private function checkConfiguration(): bool
    {
        $this->line('📋 <fg=yellow>Checking configuration...</>');

        $certPath = config('verifactu.certificate.path');
        $certPassword = config('verifactu.certificate.password');
        $environment = config('verifactu.aeat.environment');

        if (! $certPath) {
            $this->error('❌ Certificate path not configured (VERIFACTU_CERT_PATH)');

            return false;
        }

        if (! file_exists($certPath)) {
            $this->error("❌ Certificate file not found: {$certPath}");

            return false;
        }

        if (! $certPassword) {
            $this->error('❌ Certificate password not configured (VERIFACTU_CERT_PASSWORD)');

            return false;
        }

        $this->info("   ✓ Environment: <fg=cyan>{$environment}</>");
        $this->info("   ✓ Certificate: <fg=cyan>{$certPath}</>");
        $this->newLine();

        return true;
    }

    /**
     * Test certificate loading and validation
     */
    private function testCertificate(): bool
    {
        $this->line('🔑 <fg=yellow>Testing certificate...</>');

        try {
            $certPath = (string) config('verifactu.certificate.path');
            $certPassword = (string) config('verifactu.certificate.password');

            /** @var CertificateManager $manager */
            $manager = app(CertificateManager::class);
            $loaded = $manager->load($certPath, $certPassword);

            $this->info('   ✓ Certificate loaded successfully');
            $this->line("   • Subject CN: <fg=cyan>{$loaded->commonName}</>");
            $this->line('   • Valid From: <fg=cyan>' . $loaded->validFrom->format('Y-m-d H:i:s') . '</>');
            $this->line('   • Valid To:   <fg=cyan>' . $loaded->validTo->format('Y-m-d H:i:s') . '</>');
            $this->line('   • Chain certificates: <fg=cyan>' . count($loaded->chain) . '</>');
            $this->newLine();

            return true;
        } catch (\Exception $e) {
            $this->error("❌ Certificate error: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Test SOAP connection to AEAT
     */
    private function testSoapConnection(): bool
    {
        $this->line('🌐 <fg=yellow>Testing AEAT SOAP connection...</>');

        try {
            $environment = (string) config('verifactu.aeat.environment');
            $certificateType = (string) config('verifactu.certificate.type', 'representante');

            /** @var EndpointResolverContract $endpointResolver */
            $endpointResolver = app(EndpointResolverContract::class);
            $endpoint = $endpointResolver->resolve($environment, $certificateType);

            $wsdl = config("verifactu.aeat.wsdl.{$environment}")
                ?? dirname(__DIR__, 2) . '/resources/wsdl/SistemaFacturacion.wsdl';

            $this->info("   ✓ WSDL: <fg=cyan>{$wsdl}</>");
            $this->info("   ✓ Endpoint ({$certificateType}): <fg=cyan>{$endpoint}</>");

            $certPath = (string) config('verifactu.certificate.path');
            $certPassword = (string) config('verifactu.certificate.password');

            // Extract certificate to temp files for the TLS context
            $pkcs12 = file_get_contents($certPath);

            if ($pkcs12 === false) {
                throw new \RuntimeException('Cannot read certificate file');
            }

            $certs = [];
            if (! openssl_pkcs12_read($pkcs12, $certs, $certPassword)) {
                throw new \RuntimeException('Cannot read certificate (invalid password?)');
            }

            $tempCertFile = (string) tempnam(sys_get_temp_dir(), 'verifactu_cert_');
            $tempKeyFile = (string) tempnam(sys_get_temp_dir(), 'verifactu_key_');

            file_put_contents($tempCertFile, $certs['cert']);
            file_put_contents($tempKeyFile, $certs['pkey']);

            try {
                $sslContext = [
                    'verify_peer' => (bool) config('verifactu.aeat.verify_ssl', true),
                    'verify_peer_name' => (bool) config('verifactu.aeat.verify_ssl', true),
                    'local_cert' => $tempCertFile,
                    'local_pk' => $tempKeyFile,
                    'passphrase' => $certPassword,
                ];

                $soapClient = new \SoapClient($wsdl, [
                    'location' => $endpoint,
                    'connection_timeout' => 10,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'stream_context' => stream_context_create(['ssl' => $sslContext]),
                ]);

                $this->info('   ✓ SOAP client built from WSDL');

                $functions = array_map('strval', $soapClient->__getFunctions() ?? []);
                $this->info('   ✓ Operations: <fg=gray>' . implode(', ', array_unique($functions)) . '</>');

                // Probe the endpoint with mutual TLS: any HTTP response means
                // the transport-level certificate authentication succeeded.
                $probeContext = stream_context_create([
                    'ssl' => $sslContext,
                    'http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true],
                ]);

                $probe = @file_get_contents($endpoint, false, $probeContext);
                /** @var array<int, string> $http_response_header */
                $statusLine = $http_response_header[0] ?? null;

                if ($probe === false && $statusLine === null) {
                    $this->error('❌ TLS probe failed: could not establish a mutual-TLS connection to the endpoint');

                    return false;
                }

                $this->info("   ✓ Mutual-TLS probe response: <fg=cyan>{$statusLine}</>");
            } finally {
                if (file_exists($tempCertFile)) {
                    unlink($tempCertFile);
                }
                if (file_exists($tempKeyFile)) {
                    unlink($tempKeyFile);
                }
            }

            $this->newLine();

            return true;
        } catch (\SoapFault $e) {
            $this->error("❌ SOAP error: {$e->faultstring}");
            $this->error("   Code: {$e->faultcode}");

            return false;
        } catch (\Exception $e) {
            $this->error("❌ Connection error: {$e->getMessage()}");

            return false;
        }
    }
}
