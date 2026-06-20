# Work note — fiscal immutability vs right-to-erasure (for lara-privacy)

- **Status:** resolved (2026-06-19) — see Resolution below
- **Date:** 2026-06-19
- **Origin:** `lara-privacy` fit analysis (`fit-analysis.md`)
- **Follow-up:** seal-lock enforcement → Linear AID-220 (under epic AID-209)

## Context

`lara-verifactu` seals invoices (immutable, AEAT hash chain). `lara-privacy` resolves the GDPR right to erasure (Art. 17) as **anonymisation under legal hold**; physical delete only when nothing must be kept. When the fiscal hold (6 years) expires, an edge case appears.

## The decision

When the retention hold on a **sealed** fiscal artefact expires, what happens to the PII inside it?

- **(a)** Anonymise the sealed snapshot after the hold expires — does this break the VeriFACTU seal / hash chain / AEAT verifiability?
- **(b)** Treat sealed fiscal artefacts as a **permanent, documented retention exception** (the erasure right does not reach data sealed under a fiscal obligation).

## To analyse here (lara-verifactu)

- Is the VeriFACTU immutability/hash **permanent**, or does the obligation end so the record may then change?
- Would anonymising a field **break the verification chain** or the AEAT record?
- If immutability is permanent → option **(b)**: document that fiscally sealed data is outside the scope of erasure, on the legal basis of the tax obligation. Confirm this is defensible (it likely is) and state it explicitly.

## Why it matters before TDD

`lara-privacy`'s anonymisation pipeline must know whether a sealed fiscal field is **anonymisable-after-hold** or a **permanent exception**, so it never promises an erasure it cannot legally or technically deliver.

## Resolution (2026-06-19)

**A sealed VeriFACTU artefact is immutable while a retention obligation exists. Decisions are taken at the whole-artefact level — never field-level surgery inside the sealed XML or hash chain.**

Field-by-field anonymisation is **rejected**. Recipient PII (`Destinatarios` → `NombreRazon` / `IDDestinatario`, `XmlBuilder::buildDestinatarios`) is *not* among the 8 hash inputs (`HashGenerator::generate`), so scrubbing it would not break `verify()` — but mutating the stored `xml` / `signed_xml` would make the local record diverge from what was submitted to AEAT, breaking the **inalterabilidad / trazabilidad** that RD 1007/2023 (arts. 8, 16) requires. The cryptographic survivability of the hash does not authorise touching the sealed record. lara-verifactu does not expose field-level anonymisation.

Answering the questions above:

- **Is immutability permanent?** It is binding for the sealed record's lifetime under the retention obligation. Corrections/cancellations are done with a **subsequent** record (`RegistroAnulacion`), the original kept intact (RD 1007/2023). → option **(b)** at artefact level, time-bound by the hold — not field-level (a).
- **Would anonymising a field break the chain?** A hashed field → yes. Recipient PII → not the hash, but it **would** break evidential trazabilidad vs the AEAT submission. Rejected either way.

### Time-phased rule

- **During the hold:** retain intact (GDPR Art. 17.3(b) — processing necessary for a legal obligation; retention term per LGT art. 66 / Código de Comercio art. 30).
- **After the hold:** either keep the whole artefact under a separately documented basis, or purge/archive **whole artefacts by closed periods** (preserving non-personal chain anchors/checkpoints if historical chain verification must survive). No single-field deletion inside a sealed record.

### Division of labour

- **`lara-privacy`** anonymises business / CRM / user / customer data.
- **`lara-verifactu`** keeps sealed records intact and classifies `verifactu_registries` (`xml`, `signed_xml`, `hash`, `previous_hash`, `aeat_csv`, and any cotejo fields) as **non-anonymisable while retained**. The anonymisation pipeline must never promise an erasure on these it cannot deliver.

### Follow-up (not v1.0) — Linear AID-220

Enforcement is not yet in code: `Invoice::deleting` (`src/Models/Invoice.php:474`) cascades a soft-delete to the sealed `registry`, and `forceDelete` lets the DB cascade wipe it, with no submission-state guard. The seal-lock (block `update` / `delete` of submitted registries except controlled transitions) is tracked in **AID-220**. For this conceptual decision, documenting the rule here is sufficient.

### Legal basis

RD 1007/2023 arts. 8 & 16 (integridad, conservación, trazabilidad, inalterabilidad; corrections via posterior record) · GDPR Art. 17.3(b) (no erasure where processing is necessary for a legal obligation) · LGT art. 66 · Código de Comercio art. 30 (retention term). Source of truth: BOE/AEAT + `docs/verifactu/`, never third parties.
