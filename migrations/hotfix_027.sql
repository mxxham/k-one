-- =============================================================================
-- hotfix_027 — Add picklist_bin_to_bin table for bin-to-bin consolidation tasks
-- =============================================================================

create table if not exists `picklist_bin_to_bin` (
    `id` int(11) not null auto_increment,
    `picklist_id` int(11) not null,
    `sku_id` int(11) not null,
    `product_code` varchar(50) default null,
    `source_location` varchar(30) not null,
    `source_bin_id` int(11) default null,
    `destination_location` varchar(30) not null,
    `destination_bin_id` int(11) default null,
    `quantity` decimal(12,3) not null default 0,
    `uom` varchar(20) not null default 'EA',
    `status` enum('Pending','Completed') not null default 'Pending',
    `created_at` timestamp not null default current_timestamp,
    primary key (`id`),
    key `idx_picklist_bin_to_bin_picklist_id` (`picklist_id`),
    constraint `fk_picklist_bin_to_bin_picklist` foreign key (`picklist_id`) references `picklists`(`id`) on delete cascade,
    constraint `fk_picklist_bin_to_bin_sku` foreign key (`sku_id`) references `products`(`id`)
) engine=innodb default charset=utf8mb4 collate=utf8mb4_unicode_ci;
