# Yummy Project Guidelines & Coding Standards

## 1. Code Style, Typing & Static Analysis
- **Strict Typing**: Every PHP file must declare `declare(strict_types=1);` as the first statement.
- **PHPStan Level 8**: Zero errors across the entire codebase. Do not ignore errors or use `mixed` where concrete types or generics can be expressed.
- **Attribute-Based Configuration**: Use Symfony 7.2 and Doctrine ORM 3 attributes exclusively (e.g., `#[ORM\...]`, `#[Route]`, `#[MapRequestPayload]`, `#[Groups]`, `#[SerializedName]`). Avoid legacy annotations or YAML mapping.

## 2. Entity & Method Documentation Standards
- **100% Entity Field Documentation**: Every single property on Doctrine entities must have a dedicated PHPDoc block detailing:
  1. Its functional purpose and business role.
  2. Database storage format, constraints, length limits, or decimal precision.
  3. Default values and nullability.
  4. Privacy and visibility implications.
- **Class & Method Docblocks**: Every public class, public method, and repository query method must have a meaningful high-level docblock explaining purpose, parameters, return types, and thrown exceptions.
- **Inline Implementation Comments**: Provide clear inline explanations for non-obvious implementations (e.g., packed binary representations, bitwise/byte manipulation, query string conversions, concurrency handling, or failover logic).

## 3. MariaDB & Spatial Data Invariants
- **MariaDB Prepared Statement Spatial Point Format**:
  - Binding plain text strings like `'POINT(lng lat)'` to a `POINT SRID 4326` column in MariaDB prepared statements fails with SQL error `1416 Cannot get geometry object from data you send to the GEOMETRY field`.
  - Always bind geometry parameters using the 25-byte packed Well-Known Binary (WKB) format:
    `pack('V', 4326) . pack('C', 1) . pack('V', 1) . pack('e', $longitude) . pack('e', $latitude)`.
  - Store portable coordinates as `DECIMAL(10,7)` strings alongside the internal `POINT` column to prevent float rounding errors and simplify serialization.
- **UUID Parameter Binding in DQL**:
  - MariaDB stores Symfony UUIDs as `BINARY(16)`.
  - When querying `IN (:ids)` against UUID columns in DQL, convert strings via `$uuid->toBinary()` so Doctrine binds binary strings and ensures exact binary index matching.

## 4. Concurrency & Revision Management
- **Optimistic Concurrency Control**:
  - Entities using `#[ORM\Version]` must let Doctrine manage version increments. Never manually call `$entity->setVersion(...)` prior to `$em->flush()`.
  - Validate the client-provided version against `$entity->getVersion()`. If mismatched, immediately return HTTP 409 Conflict.
  - Wrap `$em->flush()` in a `try/catch (\Doctrine\ORM\OptimisticLockException)` to handle concurrent race conditions and return HTTP 409.
- **Append-Only / Forward-Reverting Audit Lineage**:
  - Historical revisions must remain immutable. Never delete or update existing revision records.
  - Reverting to a past revision restores the entity attributes, flushes to advance the version counter, and logs a new forward revision $N+1$ capturing the restored snapshot.

## 5. Security & Privacy Guardrails
- **Enumeration Prevention**:
  - Unauthenticated requests for private entities must return HTTP 404 Not Found (not 403 Forbidden) to prevent malicious actors from probing whether private resources exist.
- **CSRF Protection**:
  - All state-mutating requests (`POST`, `PATCH`, `PUT`, `DELETE`) require a valid `X-XSRF-TOKEN` cookie/header pair.
- **Third-Party API Privacy**:
  - External search services (e.g. Photon, Nominatim) must be proxied through the backend with MariaDB query caching to protect client IP addresses and respect upstream rate limits.

## 6. Testing & Quality Requirements
- **Coverage Target**: Maintain $\ge 90\%$ line coverage across all backend services, controllers, and entities.
- **Meaningful State Assertions**: Tests must not merely check HTTP status codes. They must assert database state changes, entity attribute values, side effects, and concurrency conflict rejections.
- **Database Isolation**: Tests must execute table truncation in `setUp()` with foreign key checks toggled to guarantee zero cross-test state leakage.
- **Zero External Dependencies**: External HTTP calls must be mocked with `MockHttpClient` and synthetic fixtures so test execution is offline, fast, and deterministic.
