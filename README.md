# EdTech-URN Identifier Specification

**Version:** 1.0
**Maintainer:** EdTech.ID Team
**Base Domain:** `https://urn.edtech.or.id/`

---

## 🔥 Purpose

This document defines the structure and convention for unique and persistent identifiers used by the EdTech.ID ecosystem, following a clear and flexible pattern inspired by DOI and URN standards.

---

## 🌐 Identifier Structure

### Long Form (Descriptive)

<https://urn.edtech.or.id/edtech.[namespace]/[suffix>]

### Short Form (Compact)

<https://urn.edtech.or.id/[shorthand]/[suffix>]

Both forms resolve to the same target resource.

---

## 🔖 Namespace Mapping

| Object Type              | Long Form Prefix        | Short Form Prefix | Example Suffix       | Example URLs                                                |
|---------------------------|--------------------------|--------------------|------------------------|-------------------------------------------------------------|
| Journal Article           | `edtechid.journal`         | `ej`               | `2025.0012`            | `https://urn.edtech.or.id/edtechid.journal/2025.0012`<br>`https://urn.edtech.or.id/ej/2025.0012` |
| Dataset                   | `edtechid.dataset`         | `ed`               | `2025.0456`            | `https://urn.edtech.or.id/edtechid.dataset/2025.0456`<br>`https://urn.edtech.or.id/ed/2025.0456` |
| Course Module             | `edtechid.course`          | `ec`               | `ai-2025`              | `https://urn.edtech.or.id/edtechid.course/ai-2025`<br>`https://urn.edtech.or.id/ec/ai-2025` |
| Educational Material      | `edtechid.material`        | `em`               | `2025.vid-0003`        | `https://urn.edtech.or.id/edtechid.material/2025.vid-0003`<br>`https://urn.edtech.or.id/em/2025.vid-0003` |
| User or Author ID         | `edtechid.person`          | `ep`               | `u-2025-0015`          | `https://urn.edtech.or.id/edtechid.person/u-2025-0015`<br>`https://urn.edtech.or.id/ep/u-2025-0015` |

---

## 💡 Suffix Guidelines

| Format            | Purpose                              | Example           |
|--------------------|---------------------------------------|-------------------|
| `YYYY.Serial`      | Year-based sequential publications    | `2025.0012`       |
| `Slug`             | Human-readable semantic identifier    | `ai-in-edtech`    |
| `UUID`             | Machine-generated uniqueness          | `550e8400-e29b...`|

---

## 🔧 Resolver Policy

- Both long and short forms **must resolve** to the same resource using HTTP 301/302.
- Long form is recommended for metadata and archival records.
- Short form is recommended for QR codes, UI links, and user-friendly citations.

---

## 📌 Example Usage

```plaintext
Long:  https://urn.edtech.or.id/edtechid.journal/2025.0012
Short: https://urn.edtech.or.id/ej/2025.0012
```

## 📝 Notes

1. This identifier system is controlled by EdTech.ID.
2. External registries must not assume global uniqueness outside this namespace.
3. Future versions may extend namespaces or suffix logic.

## 💼 Contact

For questions, suggestions, or integration:

Teknologi Pendidikan ID DPTSI
<dptsi@teknologipendidikan.or.id>
