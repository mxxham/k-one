<?php

/**
 * Business Rules Configuration — K-one WMS
 *
 * Centralised, single-source-of-truth for hardcoded business values
 * previously scattered across service classes.  Edit this file instead
 * of hunting through PHP classes.
 *
 * Naming conventions follow config/database.php style:
 *   arrays  → UPPER_SNAKE_CASE
 *   scalars → UPPER_SNAKE_CASE
 */

/* ------------------------------------------------------------------ */
/* UOM → Pallet conversion rates                                       */
/* ------------------------------------------------------------------ */

/** Accepted units-per-pallet options per UOM type. */
define('UOM_OPTIONS', [
    'Drum'   => [4],
    'Carton' => [36, 44, 48],
    'Pail'   => [24],
    'EA'     => [4],
    'Bags'   => [1],
]);

/** Default (preferred) units-per-pallet per UOM type. */
define('UOM_DEFAULT_PALLET', [
    'Drum'   => 4,
    'Carton' => 44,
    'Pail'   => 24,
]);

/** Legacy const alias kept for backward compat with PalletHelper::UOM_PALLET. */
define('UOM_PALLET_OPTIONS', [
    'Drum'   => 4,
    'Carton' => [36, 44, 48],
    'Pail'   => 24,
]);

/** Fallback UPP when UOM type is unknown. */
define('UOM_FALLBACK_UPP', 4);

/** Default liters per unit (Drum = 209 L). */
define('UOM_DEFAULT_LITERS_PER_UNIT', 209);

/** Default UOM type when product record has none. */
define('UOM_DEFAULT_TYPE', 'Drum');

/** Default UOM string used in SQL defaults (legacy 'EA'). */
define('UOM_DEFAULT_SQL', 'EA');


/* ------------------------------------------------------------------ */
/* Default UOM per pallet (product-level fallback)                      */
/* ------------------------------------------------------------------ */
define('DEFAULT_UOM_PER_PALLET', 4);


/* ------------------------------------------------------------------ */
/* Product quantity limits (fallback defaults)                          */
/* ------------------------------------------------------------------ */
define('DEFAULT_MAX_SKU_QTY', 44);
define('DEFAULT_MAX_TRANS_QTY', 80);


/* ------------------------------------------------------------------ */
/* Expiry / shelf-life defaults                                         */
/* ------------------------------------------------------------------ */

/** Default shelf-life in years (manufacture → expiry). */
define('DEFAULT_SHELF_LIFE_YEARS', 4);

/** Days threshold for "expiring soon" warning. */
define('EXPIRY_WARNING_DAYS', 30);

/** Days threshold for "critical" expiry alert. */
define('EXPIRY_CRITICAL_DAYS', 120);

/** Days threshold for "approaching expiry" (used in Stock::getExpiryInfo). */
define('EXPIRY_APPROACHING_DAYS', 180);

/** Days threshold used by Stock::getAll($expiring=true). */
define('EXPIRY_DASHBOARD_DAYS', 90);


/* ------------------------------------------------------------------ */
/* Location / warehouse constants                                      */
/* ------------------------------------------------------------------ */

/** Staging area location code. */
define('LOCATION_STAGING', 'STAGING');

/** Quarantine location code for rejected/damaged goods. */
define('LOCATION_QUARANTINE', 'QUA_SHELL');

/** Unallocated fallback location code. */
define('LOCATION_UNALLOCATED', 'UNALLOCATED');

/** Base location prefix for PalletHelper::generateLocations(). */
define('LOCATION_BASE_PREFIX', 'SUB50');

/** Default special locations that bypass location_master validation. */
define('SPECIAL_LOCATIONS', ['QUA_SHELL', 'STAGING']);

/** Location levels used for full-pallet putaway (reserve rows). */
define('RESERVE_LEVELS', ['B', 'C', 'D', 'E']);

/** Location level used for partial-pallet / pick-face putaway. */
define('PICK_FACE_LEVEL', 'A');

/** Default pick-face level character for levelOf() fallback. */
define('DEFAULT_LEVEL', 'B');


/* ------------------------------------------------------------------ */
/* Status constants — per module                                        */
/* ------------------------------------------------------------------ */

/** Inbound order statuses. */
define('INBOUND_STATUSES', [
    'DEFAULT'        => 'Draft',
    'DRAFT'          => 'Draft',
    'DUES_IN'        => 'Dues In',
    'RECEIVING'      => 'Receiving',
    'COMPLETED'      => 'Completed',
]);

/** Inbound item in-process statuses. */
define('INBOUND_ITEM_PROCESSES', [
    'DUES_IN'        => 'Dues In',
    'GOODS_RECEIVED' => 'Goods Received',
    'ATP'            => 'ATP',
    'UNSERVICEABLE'  => 'Unserviceable',
]);

/** Allowed inbound item status transitions. */
define('INBOUND_ALLOWED_PROCESSES', ['Dues In', 'Goods Received', 'Unserviceable', 'ATP']);

/** Inbound item in-process → stock badge mapping. */
define('INBOUND_STOCK_BADGE', [
    'Dues In'        => 'Pending',
    'Goods Received' => 'Pending',
    'ATP'            => 'Accepted',
    'Unserviceable'  => 'Rejected',
]);

/** Inbound item in-process → stock_status mapping (complete flow). */
define('INBOUND_STOCK_STATUS_MAP', [
    'ATP'            => 'Available',
    'Picked'         => 'Available',
    'Dues In'        => 'Dues In',
    'Unserviceable'  => 'Rejected',
]);

/** Inbound item default stock_status. */
define('INBOUND_DEFAULT_STOCK_STATUS', 'Pending');

/** Inbound item default in_process_status. */
define('INBOUND_DEFAULT_PROCESS_STATUS', 'Dues In');

/** Outbound order statuses. */
define('OUTBOUND_STATUSES', [
    'DEFAULT'    => 'Open',
    'OPEN'       => 'Open',
    'PICKING'    => 'Picking',
    'SHIPPED'    => 'Shipped',
    'COMPLETED'  => 'Completed',
]);

/** Outbound item default status. */
define('OUTBOUND_DEFAULT_STATUS', 'Open');

/** Stock / stock_location status values. */
define('STOCK_STATUSES', [
    'AVAILABLE'   => 'Available',
    'RESERVED'    => 'Reserved',
    'PICKED'      => 'Picked',
    'DUES_IN'     => 'Dues In',
    'PENDING'     => 'Pending',
    'ACCEPTED'    => 'Accepted',
    'REJECTED'    => 'Rejected',
    'EXPIRED'     => 'Expired',
]);

/** Hold / quarantine status values. */
define('HOLD_STATUSES', ['on_hold', 'quarantine', 'damaged']);

/** Alias for Stock class constant (class constants cannot reference same-named globals). */
define('HOLD_STATUSES_VAL', ['on_hold', 'quarantine', 'damaged']);

/** Hold clause: clause fragment for "available" hold status. */
define('HOLD_AVAILABLE_CLAUSE', "(hold_status = 'available' OR hold_status IS NULL)");

/** Putaway task statuses. */
define('PUTAWAY_STATUSES', [
    'OPEN'        => 'Open',
    'IN_PROGRESS' => 'In Progress',
    'COMPLETED'   => 'Completed',
]);

/** Picklist statuses. */
define('PICKLIST_STATUSES', [
    'DRAFT'     => 'Draft',
    'CONFIRMED' => 'Confirmed',
    'PICKING'   => 'Picking',
    'COMPLETED' => 'Completed',
]);

/** ASN statuses. */
define('ASN_STATUSES', [
    'PENDING'  => 'Pending',
    'RECEIVED' => 'Received',
]);

/** Stock-take statuses. */
define('STOCKTAKE_STATUSES', [
    'COUNTING' => 'Counting',
    'REVIEW'   => 'Review',
]);


/* ------------------------------------------------------------------ */
/* Stock location exclusion list                                       */
/* ------------------------------------------------------------------ */
define('STOCK_LOCATION_EXCLUSIONS', ['QUA_SHELL', 'STAGING']);

/** Cross-dock specific exclusions (STAGING allowed for cross-dock). */
define('STOCK_LOCATION_EXCLUSIONS_CROSS_DOCK', ['QUA_SHELL']);


/* ------------------------------------------------------------------ */
/* Order number prefixes                                               */
/* ------------------------------------------------------------------ */
define('INBOUND_NUMBER_PREFIX', 'IN-');
define('OUTBOUND_NUMBER_PREFIX', 'OUT-');

/** Sequence padding length for order numbers. */
define('ORDER_NUMBER_SEQ_LENGTH', 4);


/* ------------------------------------------------------------------ */
/* Default UOM for outbound item fallback.                              */
/* ------------------------------------------------------------------ */
define('OUTBOUND_DEFAULT_UOM', 'Drum');

return [
    'UOM_OPTIONS'            => UOM_OPTIONS,
    'UOM_DEFAULT_PALLET'     => UOM_DEFAULT_PALLET,
    'DEFAULT_UOM_PER_PALLET' => DEFAULT_UOM_PER_PALLET,
    'EXPIRY_WARNING_DAYS'    => EXPIRY_WARNING_DAYS,
    'EXPIRY_CRITICAL_DAYS'   => EXPIRY_CRITICAL_DAYS,
    'EXPIRY_APPROACHING_DAYS'=> EXPIRY_APPROACHING_DAYS,
    'HOLD_STATUSES'          => HOLD_STATUSES,
    'INBOUND_STATUSES'       => INBOUND_STATUSES,
    'OUTBOUND_STATUSES'      => OUTBOUND_STATUSES,
    'STOCK_STATUSES'         => STOCK_STATUSES,
    'LOCATION_STAGING'       => LOCATION_STAGING,
    'LOCATION_QUARANTINE'    => LOCATION_QUARANTINE,
    'LOCATION_UNALLOCATED'   => LOCATION_UNALLOCATED,
];
