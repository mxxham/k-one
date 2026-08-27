import { dbExec, dbExecFirst } from '../db';
import { apiRequireAuth, jsonErr, jsonOut, query } from '../helpers';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatDmy(value: string): string {
  const d = new Date(value);
  return String(d.getDate()).padStart(2, '0') + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
}

function numberFormat0(n: number): string {
  return Math.round(n).toLocaleString('en-US');
}

/** Port of api/handlers/dashboard.php */
export async function handleDashboard(action: string): Promise<void> {
  switch (action) {
    case 'stats': {
      apiRequireAuth();

      const stockByUOM = await dbExec(`SELECT p.uom_type, SUM(s.quantity) as total_qty, SUM(s.pallet) as total_pallet
        FROM stock s JOIN products p ON s.product_id = p.id
        WHERE s.stock_status = 'Available' GROUP BY p.uom_type`);
      let totalDrums = 0;
      let totalPallets = 0;
      for (const u of stockByUOM) {
        totalDrums += Number(u.total_qty);
        totalPallets += Number(u.total_pallet);
      }

      const expiringSoon = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM stock
        WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
        AND expiry_date > CURDATE() AND stock_status = 'Available'`))?.count ?? 0);
      const expiredItems = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM stock
        WHERE expiry_date < CURDATE() AND stock_status = 'Available'`))?.count ?? 0);
      const duesInCount = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Dues In'`))?.count ?? 0);
      const receivingNow = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Receiving'`))?.count ?? 0);
      const pendingOutbound = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM outbound_orders WHERE status IN ('Open','Picking')`))?.count ?? 0);
      const dispatchedToday = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM outbound_orders WHERE status = 'Completed' AND DATE(updated_at) = CURDATE()`))?.count ?? 0);
      const receivedToday = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM inbound_orders WHERE status IN ('Goods Received','Good Received') AND DATE(updated_at) = CURDATE()`))?.count ?? 0);
      const todayInbound = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM inbound_orders WHERE order_date = CURDATE()`))?.count ?? 0);
      const todayOutbound = Number((await dbExecFirst(`SELECT COUNT(*) as count FROM outbound_orders WHERE order_date = CURDATE()`))?.count ?? 0);

      const expiredDetail = expiredItems > 0 ? await dbExec(`SELECT p.product_code, p.product_name, s.batch_number, s.expiry_date,
        SUM(s.quantity) as qty, SUM(s.pallet) as pallet
        FROM stock s JOIN products p ON s.product_id = p.id
        WHERE s.expiry_date < CURDATE() AND s.stock_status = 'Available'
        GROUP BY p.id, p.product_code, p.product_name, s.batch_number, s.expiry_date
        ORDER BY s.expiry_date ASC LIMIT 5`) : [];

      const stockSummary = await dbExec(`SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
        COUNT(DISTINCT s.batch_number) as batches,
        SUM(s.quantity) as total_qty, SUM(s.pallet) as total_pallet,
        MIN(s.expiry_date) as nearest_expiry,
        SUM(CASE WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE() THEN 1 ELSE 0 END) as expiring_count
        FROM stock s JOIN products p ON s.product_id = p.id
        WHERE s.stock_status = 'Available'
        GROUP BY p.id HAVING total_qty > 0
        ORDER BY nearest_expiry ASC, total_qty DESC LIMIT 25`);

      const monthlyActivity = await dbExec(`SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN transaction_type = 'IN' THEN quantity_in ELSE 0 END) as inbound_qty,
        SUM(CASE WHEN transaction_type = 'OUT' THEN quantity_out ELSE 0 END) as outbound_qty
        FROM stock_ledger WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m') ORDER BY month DESC`);

      const stockByLocation = await dbExec(`SELECT lm.aisle,
        COUNT(DISTINCT lm.location_code) as total_locs,
        COUNT(DISTINCT CASE WHEN s1.quantity > 0 OR s2.quantity > 0 THEN lm.location_code END) as occupied_locs,
        COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.quantity ELSE s2.quantity END), 0) as total_qty,
        CEIL(COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.pallet ELSE s2.pallet END), 0)) as total_pallet
        FROM location_master lm
        LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
            AND sl.status IN ('Available','Reserved')
        LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
        LEFT JOIN stock s2 ON s2.location COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
            AND s2.quantity > 0 AND s2.stock_status = 'Available' AND s1.id IS NULL
        WHERE lm.is_active = 1
        GROUP BY lm.aisle ORDER BY lm.aisle`);

      const recentActivity = await dbExec(`SELECT sl.*, p.product_code, p.product_name
        FROM stock_ledger sl JOIN products p ON sl.product_id = p.id
        ORDER BY sl.id DESC LIMIT 8`);

      const pendingInboundQ = await dbExec(`SELECT io.id, io.order_number, io.status, io.order_date, io.shipment_no, io.carrier_name,
        COUNT(ii.id) AS line_count, COALESCE(SUM(ii.quantity), 0) AS total_qty
        FROM inbound_orders io
        LEFT JOIN inbound_items ii ON ii.inbound_order_id = io.id
        WHERE io.status IN ('Dues In','Receiving')
        GROUP BY io.id, io.order_number, io.status, io.order_date, io.shipment_no, io.carrier_name
        ORDER BY FIELD(io.status,'Receiving','Dues In'), io.order_date ASC LIMIT 10`);

      const pendingOutboundQ = await dbExec(`SELECT oo.id, oo.order_number, oo.status, oo.order_date, oo.shipment_number,
        COUNT(oi.id) AS line_count, COALESCE(SUM(oi.quantity), 0) AS total_qty
        FROM outbound_orders oo
        LEFT JOIN outbound_items oi ON oi.outbound_order_id = oo.id
        WHERE oo.status IN ('Open','Picking')
        GROUP BY oo.id, oo.order_number, oo.status, oo.order_date, oo.shipment_number
        ORDER BY FIELD(oo.status,'Picking','Open'), oo.order_date ASC LIMIT 10`);

      jsonOut({
        kpi: {
          total_drums: Number(totalDrums),
          total_pallets: Number(totalPallets),
          expiring_soon: expiringSoon,
          expired_items: expiredItems,
          dues_in: duesInCount,
          receiving_now: receivingNow,
          pending_outbound: pendingOutbound,
          dispatched_today: dispatchedToday,
          received_today: receivedToday,
          today_inbound: todayInbound,
          today_outbound: todayOutbound,
          stock_by_uom: stockByUOM,
        },
        expired_detail: expiredDetail,
        stock_summary: stockSummary,
        monthly_activity: monthlyActivity,
        stock_by_location: stockByLocation,
        recent_activity: recentActivity,
        pending_inbound: pendingInboundQ,
        pending_outbound: pendingOutboundQ,
      });
    }

    case 'aisle_detail': {
      apiRequireAuth();
      const aisle = String(query('aisle') ?? '').trim();
      if (!aisle) jsonErr('aisle required');
      const locations = await dbExec(`SELECT
        lm.location_code AS code, lm.rack, lm.row_name, lm.zone,
        COALESCE(s1.quantity, s2.quantity, 0) AS qty,
        COALESCE(s1.pallet, s2.pallet, 0) AS pallet,
        COALESCE(s1.uom, s2.uom) AS uom,
        COALESCE(s1.batch_number, s2.batch_number) AS batch,
        COALESCE(s1.expiry_date, s2.expiry_date) AS expiry,
        COALESCE(p1.product_name, p2.product_name) AS product,
        COALESCE(p1.product_code, p2.product_code) AS product_code,
        COALESCE(p1.uom_per_pallet, p2.uom_per_pallet) AS uom_per_pallet
        FROM location_master lm
        LEFT JOIN stock_locations sl
            ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
            AND sl.status IN ('Available','Reserved')
        LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
        LEFT JOIN products p1 ON s1.product_id = p1.id
        LEFT JOIN stock s2
            ON s2.location COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
            AND s2.quantity > 0 AND s2.stock_status = 'Available' AND s1.id IS NULL
        LEFT JOIN products p2 ON s2.product_id = p2.id
        WHERE lm.aisle = ? AND lm.is_active = 1
        ORDER BY lm.rack, lm.row_name, lm.position`, [aisle]);
      for (const l of locations) {
        l.qty = Number(l.qty);
        l.pallet = Number(l.pallet);
        l.is_eceran = l.row_name === 'A';
        const upp = Number(l.uom_per_pallet ?? 4);
        l.is_partial = l.is_eceran || (!l.is_eceran && l.qty > 0 && upp > 0 && l.qty < upp);
        if (!l.is_eceran && l.pallet > 0) l.pallet = Math.ceil(l.pallet);
        if (l.expiry) l.expiry = formatDmy(l.expiry);
      }
      const total = locations.length;
      const occupied = locations.filter((l: any) => l.qty > 0).length;
      const totalQty = locations.reduce((s: number, l: any) => s + Number(l.qty), 0);
      const totalPlt = Math.ceil(locations.reduce((s: number, l: any) => s + Number(l.pallet), 0));
      jsonOut({
        locations,
        stats: {
          aisle,
          total,
          occupied,
          total_qty: numberFormat0(totalQty),
          total_pallet: totalPlt,
        },
      });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}
