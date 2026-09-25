-- ============================================================================
--  Sections by type AND by package, packages given with a type
--
--  packages.modules            the sections this package turns on (JSON list
--                              of keys from app/Services/Modules.php). NULL or
--                              [] = it turns none on by itself. A full-access
--                              package turns every section on.
--  student_types.package_ids   packages every student of this type receives
--                              when the type is approved, and loses when the
--                              type changes (JSON list of package ids).
--  package_activations.source  where an activation came from: NULL = an
--                              admin, a code, a purchase …; 'type:<id>' = a
--                              student type, so changing the type withdraws
--                              exactly those and nothing else.
--
--  A section is on for a student when it is on site-wide AND (their type
--  allows it OR one of their live packages turns it on).
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE packages ADD COLUMN IF NOT EXISTS modules JSON NULL AFTER is_free;
ALTER TABLE student_types ADD COLUMN IF NOT EXISTS package_ids JSON NULL AFTER modules;
ALTER TABLE package_activations ADD COLUMN IF NOT EXISTS source VARCHAR(32) NULL AFTER activated_by;
ALTER TABLE package_activations ADD KEY IF NOT EXISTS idx_activation_source (source);
