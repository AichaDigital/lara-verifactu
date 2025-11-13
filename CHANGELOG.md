# Changelog

All notable changes to `lara-verifactu` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nothing yet

### Changed
- Nothing yet

## [0.1.0] - 2025-10-12

### Added - Phase 1: Base Structure
- Initial package structure following Spatie best practices
- Contract-based agnostic architecture
- Core contracts: InvoiceContract, RegistryContract, HashGeneratorContract, QrGeneratorContract, XmlBuilderContract, AeatClientContract, CertificateManagerContract
- Comprehensive enum system: InvoiceTypeEnum, TaxTypeEnum, RegimeTypeEnum, OperationTypeEnum, IdTypeEnum, RegistryStatusEnum
- Complete exception hierarchy: VerifactuException and 9 specialized exception classes
- LaraVerifactuServiceProvider with automatic service binding
- Verifactu Facade for fluent API
- Configuration file with native and custom mode support
- Support for Laravel 12
- PHPStan level 8 configuration
- Laravel Pint code style configuration
- Pest testing framework setup
- GitHub Actions CI/CD workflows
- Comprehensive documentation (README, CONTRIBUTING, CHANGELOG, GETTING_STARTED)
- Project-specific Cursor rules and guidelines

### Added - Phase 2: Core Services
- **HashGenerator Service**: Generate SHA-256 hashes according to AEAT specifications (14 tests)
- **QrGenerator Service**: Generate QR codes in SVG/PNG formats (10 tests)
- **XmlBuilder Service**: Build XML according to AEAT XSD schema (14 tests)
- **CertificateManager Service**: Manage digital certificates for AEAT authentication (6 tests)
- **AeatClient Service**: SOAP client for AEAT web services communication (8 tests)
- Complete test coverage: 52 active tests passing
- All services fully documented with PHPDoc
- Integration with certificate signing
- Proper error handling and logging

### Added - Phase 3: Models & Persistence
- **Invoice Model**: Eloquent model implementing InvoiceContract (12 tests)
- **Registry Model**: Eloquent model implementing RegistryContract (15 tests)
- **InvoiceBreakdown Model**: Eloquent model implementing InvoiceBreakdownContract (11 tests)
- Database migrations with proper indexes and constraints
- Model factories for testing and seeding
- Comprehensive model relationships (hasOne, hasMany, belongsTo)
- Soft delete support with cascade
- Type casting for enums, decimals, dates, and JSON
- Model feature tests with RefreshDatabase

### Added - Phase 4: Service Integration
- **RegistryManager Service**: Orchestrate registry operations
  - Create registries with hash generation
  - Manage blockchain integrity
  - Track submission status
  - Generate registry numbers
  - Retry failed submissions
- **InvoiceRegistrar Service**: Main orchestrator for invoice registration
  - Complete registration workflow
  - AEAT submission handling
  - Batch processing support
  - Retry logic for failed submissions
  - Blockchain verification
- Updated HashGenerator and XmlBuilder to work with Invoice models
- Service integration tests

### Added - Phase 5: Commands & Jobs
- **Artisan Commands** (4):
  - `verifactu:register` - Register invoices (single or batch)
  - `verifactu:retry-failed` - Retry failed AEAT submissions
  - `verifactu:verify-blockchain` - Verify blockchain integrity
  - `verifactu:status` - Show system status dashboard
- **Queue Jobs** (4):
  - `ProcessInvoiceRegistrationJob` - Full invoice registration process
  - `SubmitRegistryToAeatJob` - Submit registry to AEAT
  - `RetryFailedRegistriesJob` - Batch retry failed registries
  - `VerifyBlockchainIntegrityJob` - Verify blockchain integrity
- All jobs configured with retries, timeouts, and backoff
- Command and job tests (12 tests)

### Added - Phase 6: Events & Listeners
- **Events** (5):
  - `InvoiceRegisteredEvent` - Fired when invoice is registered
  - `RegistryCreatedEvent` - Fired when registry is created
  - `RegistrySubmittedEvent` - Fired on successful AEAT submission
  - `RegistryFailedEvent` - Fired on failed AEAT submission
  - `BlockchainVerifiedEvent` - Fired after blockchain verification
- **Listeners** (5):
  - `LogInvoiceRegistration` - Logs invoice registrations
  - `LogRegistryCreation` - Logs registry creations
  - `LogRegistrySubmission` - Logs successful submissions
  - `LogRegistryFailure` - Logs failed submissions
  - `LogBlockchainVerification` - Logs verification results
- Event-listener registration in ServiceProvider
- Comprehensive event logging with context data
- Event tests (6 tests)

### Added - Phase 7: AEAT API Integration
- **Real SOAP Client**: Production-ready AEAT web service integration
  - Automatic SSL certificate authentication (.p12/.pfx support)
  - Certificate extraction and temporary file management
  - Full WSDL support for both sandbox and production
  - Proper SOAP operation calls (`RegFactuSistemaFacturacion`)
- **XAdES-EPES Digital Signature**: XML signature using xmlseclibs
  - RSA-SHA256 signature algorithm
  - X.509 certificate embedding
  - Enveloped signature support
  - Proper canonicalization (EXC_C14N)
- **Enhanced AeatClient**:
  - Real certificate-based authentication
  - Detailed SOAP request/response logging
  - AEAT response parsing (CSV, EstadoEnvio, CodigoSeguro)
  - Comprehensive error handling
- **Enhanced CertificateManager**:
  - XAdES-EPES signature implementation
  - Full .p12 certificate support
  - Certificate validation and date checking
  - Private key extraction and management
- **TestAeatConnectionCommand**: New command to verify AEAT connectivity
  - Certificate information display
  - SOAP client initialization test
  - Available methods discovery
  - Connection troubleshooting
- **Dependencies**: Added robrichards/xmlseclibs for XML security
- **Configuration**: Updated endpoints and WSDL URLs for real AEAT services

### Status
- ⚠️ **BETA VERSION - NOT FOR PRODUCTION USE**
- ✅ Phase 1: Arquitectura base (100%)
- ✅ Phase 2: Servicios core (100%)
- ✅ Phase 3: Modelos y persistencia (100%)
- ✅ Phase 4: Integración servicios (100%)
- ✅ Phase 5: Commands & Jobs (100%)
- ✅ Phase 6: Events & Listeners (100%)
- ✅ Phase 7: AEAT API Integration (100%)
- 🚧 Phase 8: Testing & Documentation (50%)
- ⏳ Phase 9: Production hardening (planned for v1.0.0)
- **Total Progress: 92%**
- **Tests: 120/120 passing ✅**
- **PHPStan: Level 8 ✅ (12 legitimate framework false positives baselined)**
- **Code Style: PSR-12 ✅**

[Unreleased]: https://github.com/aichadigital/lara-verifactu/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/aichadigital/lara-verifactu/releases/tag/v0.1.0

