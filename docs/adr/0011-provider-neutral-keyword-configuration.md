# ADR 0011: Provider-neutral keyword configurations

- Status: Accepted
- Date: 2026-08-23

## Context

Keywords need desktop/mobile and market configuration before rank tracking exists.
Encoding Google-specific concepts in the table would couple the domain to the first
provider, while allowing arbitrary request values would produce unusable records.

## Decision

Store validated engine and device keys selected from source-controlled configuration.
Seed Google and Bing plus desktop and mobile, without implementing either provider.
Identify a configuration by website, normalized term, engine, country, language, and
device. Scope all access through the owned website. Keep target URLs optional and do
not perform network access.

## Consequences

Adding an engine or device is a release/configuration change and later requires a
tracking adapter, not a keyword-table redesign. The same term may legitimately exist
across engines, markets, languages, devices, and websites, while exact configuration
duplicates are rejected. Runtime arbitrary provider names remain invalid.
