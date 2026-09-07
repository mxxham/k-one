ALTER TABLE replen_task
    MODIFY COLUMN status enum('pending','printed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE picklist_bin_to_bin
    MODIFY COLUMN status enum('Pending','Completed','Cancelled') NOT NULL DEFAULT 'Pending';

ALTER TABLE picklist_bin_to_bin
    ADD COLUMN IF NOT EXISTS completed_at timestamp NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at timestamp NULL DEFAULT NULL;
