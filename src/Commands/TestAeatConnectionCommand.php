<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Commands;

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
            $certPath = config('verifactu.certificate.path');
            $certPassword = config('verifactu.certificate.password');

            /** @var CertificateManager $manager */
            $manager = app(CertificateManager::class);
            $manager->load($certPath, $certPassword);

            $info = $manager->getCertificateInfo();

            $this->info('   ✓ Certificate loaded successfully');
            $this->line("   • Subject: <fg=cyan>{$info['subject']}</>");
            $this->line("   • Issuer:  <fg=cyan>{$info['issuer']}</>");
            $this->line("   • Valid From: <fg=cyan>{$info['valid_from']}</>");
            $this->line("   • Valid To:   <fg=cyan>{$info['valid_to']}</>");
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
            $environment = config('verifactu.aeat.environment');
            $wsdl = config("verifactu.aeat.wsdl.{$environment}");
            $endpoint = config("verifactu.aeat.endpoints.{$environment}");

            $this->info("   ✓ WSDL: <fg=cyan>{$wsdl}</>");
            $this->info("   ✓ Endpoint: <fg=cyan>{$endpoint}</>");

            // Try to create SOAP client
            $certPath = config('verifactu.certificate.path');
            $certPassword = config('verifactu.certificate.password');

            // Extract certificate to temp files
            $pkcs12 = file_get_contents($certPath);

            if ($pkcs12 === false) {
                throw new \RuntimeException('Cannot read certificate file');
            }

            $certs = [];
            if (! openssl_pkcs12_read($pkcs12, $certs, $certPassword)) {
                throw new \RuntimeException('Cannot read certificate (invalid password?)');
            }

            $tempCertFile = tempnam(sys_get_temp_dir(), 'verifactu_cert_');
            $tempKeyFile = tempnam(sys_get_temp_dir(), 'verifactu_key_');

            file_put_contents($tempCertFile, $certs['cert']);
            file_put_contents($tempKeyFile, $certs['pkey']);

            $soapClient = new \SoapClient($wsdl, [
                'connection_timeout' => 10,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'trace' => true,
                'exceptions' => true,
                'soap_version' => SOAP_1_1,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => config('verifactu.aeat.verify_ssl', true),
                        'verify_peer_name' => config('verifactu.aeat.verify_ssl', true),
                        'local_cert' => $tempCertFile,
                        'local_pk' => $tempKeyFile,
                        'passphrase' => $certPassword,
                    ],
                ]),
            ]);

            $this->info('   ✓ SOAP client created successfully');

            // Get available methods
            $functions = $soapClient->__getFunctions();
            if ($functions !== null) {
                $this->info('   ✓ Available SOAP methods:');
                foreach (array_slice($functions, 0, 5) as $function) {
                    $this->line("     • <fg=gray>{$function}</>");
                }
                if (count($functions) > 5) {
                    $this->line('     • <fg=gray>... and ' . (count($functions) - 5) . ' more</>');
                }
            }

            // Cleanup
            @unlink($tempCertFile);
            @unlink($tempKeyFile);

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
