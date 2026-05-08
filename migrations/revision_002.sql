USE trianawms;

ALTER TABLE `inbound_orders`
  DROP FOREIGN KEY IF EXISTS `fk_inbound_received_by`,
  ADD CONSTRAINT `fk_inbound_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `outbound_orders`
  DROP FOREIGN KEY IF EXISTS `fk_outbound_shipped_by`,
  ADD CONSTRAINT `fk_outbound_shipped_by` FOREIGN KEY (`shipped_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


ALTER TABLE `stock_movements` MODIFY COLUMN `created_by` INT(11) DEFAULT NULL;
