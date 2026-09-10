-- Hotfix 032: Deprecate pick_face_targets table
-- Table has zero code references; superseded by sku_pickface_config.
-- Renamed (not dropped) for one release cycle as insurance.

RENAME TABLE pick_face_targets TO pick_face_targets_deprecated_20260910;
