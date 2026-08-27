import { dbExec, dbExecFirst, dbScalar } from '../db';

/**
 * Port of classes/LocationManager.php.
 */
export class LocationManager {
  static async getAll(zone: string | null = null, availableOnly = false): Promise<any[]> {
    let sql = `SELECT lm.*,
                COUNT(sl.id) as occupied_pallets,
                SUM(CASE WHEN sl.status = 'Available' THEN sl.quantity ELSE 0 END) as current_qty,
                MAX(sl2.batch_number) as current_batch,
                MAX(sl2.stock_id) as stock_id,
                CASE
                    WHEN COUNT(CASE WHEN sl.status = 'Available' THEN 1 END) > 0 THEN 'Occupied'
                    ELSE 'Available'
                END AS availability
            FROM location_master lm
            LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            LEFT JOIN stock_locations sl2 ON sl2.id = (
                SELECT id FROM stock_locations
                WHERE location_code = lm.location_code
                AND status IN ('Available','Reserved')
                ORDER BY id DESC LIMIT 1
            )
            WHERE lm.is_active = 1`;
    const params: any[] = [];
    if (zone) {
      sql += ' AND lm.zone = ?';
      params.push(zone);
    }
    sql += ' GROUP BY lm.id';
    if (availableOnly) {
      sql += " HAVING availability = 'Available'";
    }
    sql += ' ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position';
    return dbExec(sql, params);
  }

  static async getAvailableLocations(count = 20, preferZone: string | null = null): Promise<any[]> {
    let sql = `SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name,
                   lm.position, lm.zone
            FROM location_master lm
            WHERE lm.is_active = 1
            AND lm.location_code NOT IN (
                SELECT DISTINCT location_code FROM stock_locations
                WHERE status IN ('Available','Reserved')
            )`;
    const params: any[] = [];
    if (preferZone) {
      sql += ' ORDER BY CASE WHEN lm.zone = ? THEN 0 ELSE 1 END, lm.aisle, lm.rack, lm.row_name, lm.position';
      params.push(preferZone);
    } else {
      sql += ' ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position';
    }
    sql += ' LIMIT ' + Number(count);
    return dbExec(sql, params);
  }

  static async isAvailable(locationCode: string): Promise<boolean> {
    const n = await dbScalar(
      `SELECT COUNT(*) FROM stock_locations
       WHERE location_code = ? AND status IN ('Available','Reserved')`,
      [locationCode],
    );
    return Number(n ?? 0) === 0;
  }

  static async getLocationInfo(locationCode: string): Promise<any> {
    return dbExecFirst(
      `SELECT lm.*,
            sl.id as sl_id, sl.quantity, sl.batch_number, sl.uom, sl.status as stock_status,
            st.expiry_date, p.product_name, p.product_code
            FROM location_master lm
            LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            LEFT JOIN stock st ON sl.stock_id = st.id
            LEFT JOIN products p ON st.product_id = p.id
            WHERE lm.location_code = ?
            LIMIT 1`,
      [locationCode],
    );
  }

  static async suggestLocationsForInbound(quantity: number, uom: string, uomPerPallet = 4, preferZone: string | null = null): Promise<any> {
    if (uomPerPallet <= 0) uomPerPallet = 4;

    const fullPallets = Math.floor(Number(quantity) / Number(uomPerPallet));
    const remainder = Number(quantity) % Number(uomPerPallet);
    const totalPallets = fullPallets + (remainder > 0 ? 1 : 0);

    const fullLocations = await LocationManager.getAvailableLocationsByLevel(fullPallets + 20, preferZone, ['B', 'C', 'D', 'E']);

    if (fullLocations.length < fullPallets) {
      const extraNeeded = fullPallets - fullLocations.length;
      const extraLocs = await LocationManager.getAvailableLocations(extraNeeded + 10, preferZone);
      const existingCodes = fullLocations.map((l: any) => l.location_code);
      for (const el of extraLocs) {
        if (!existingCodes.includes(el.location_code)) {
          fullLocations.push(el);
          existingCodes.push(el.location_code);
        }
        if (fullLocations.length >= fullPallets) break;
      }
    }

    const canAssignFull = Math.min(fullPallets, fullLocations.length);

    let partialLocations: any[] = [];
    if (remainder > 0) {
      partialLocations = await LocationManager.getAvailableLocationsByLevel(5, preferZone, ['A']);
      if (partialLocations.length === 0) {
        const usedCodes = fullLocations.slice(0, canAssignFull).map((l: any) => l.location_code);
        const anyLocs = await LocationManager.getAvailableLocations(10, preferZone);
        for (const al of anyLocs) {
          if (!usedCodes.includes(al.location_code)) {
            partialLocations.push(al);
            break;
          }
        }
      }
    }

    const pallets: any[] = [];
    let palletSeq = 1;

    for (let i = 0; i < canAssignFull; i++) {
      pallets.push({
        pallet_seq: palletSeq,
        location_code: fullLocations[i].location_code,
        quantity: uomPerPallet,
        is_full: true,
        uom: uom,
      });
      palletSeq++;
    }

    if (remainder > 0) {
      pallets.push({
        pallet_seq: palletSeq,
        location_code: partialLocations[0]?.location_code ?? 'STAGING',
        quantity: remainder,
        is_full: false,
        uom: uom,
      });
    }

    const success = canAssignFull === fullPallets;
    return {
      success: success,
      message: success ? '' : `Hanya ${canAssignFull}/${fullPallets} lokasi full pallet tersedia — sisanya tidak ter-assign`,
      pallets: pallets,
      total_pallets: totalPallets,
    };
  }

  static async getAvailableLocationsByLevel(count = 20, preferZone: string | null = null, levels: string[] = ['B', 'C', 'D', 'E']): Promise<any[]> {
    const placeholders = levels.map(() => '?').join(',');
    let sql = `SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name, lm.position, lm.zone
            FROM location_master lm
            WHERE lm.is_active = 1
            AND lm.row_name IN (${placeholders})
            AND lm.location_code NOT IN (
                SELECT DISTINCT location_code FROM stock_locations
                WHERE status IN ('Available','Reserved')
            )`;
    const params: any[] = [...levels];
    if (preferZone) {
      sql += ' ORDER BY CASE WHEN lm.zone = ? THEN 0 ELSE 1 END, lm.aisle, lm.rack, lm.row_name, lm.position';
      params.push(preferZone);
    } else {
      sql += ' ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position';
    }
    sql += ' LIMIT ' + Number(count);
    return dbExec(sql, params);
  }

  static async commitInboundLocations(stockId: number, itemId: number, batchNumber: any, pallets: any[]): Promise<number> {
    for (const pallet of pallets) {
      await dbExec(
        `INSERT INTO stock_locations
                (stock_id, location_code, pallet_seq, quantity, uom,
                 is_full_pallet, batch_number, inbound_item_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')
                ON DUPLICATE KEY UPDATE
                    quantity = VALUES(quantity),
                    status   = 'Available'`,
        [
          stockId,
          pallet.location_code,
          pallet.pallet_seq,
          pallet.quantity,
          pallet.uom ?? 'EA',
          pallet.is_full ? 1 : 0,
          batchNumber,
          itemId,
        ],
      );
    }
    return pallets.length;
  }

  static async getFEFOByLocation(productId: number, requiredQty: number): Promise<any> {
    const rows = await dbExec(
      `SELECT sl.id as sl_id, sl.location_code, sl.quantity,
             sl.batch_number, sl.pallet_seq, sl.uom, sl.is_full_pallet,
             st.id as stock_id, st.expiry_date, st.manufacture_date,
             st.product_id
        FROM stock_locations sl
        JOIN stock st ON sl.stock_id = st.id
        WHERE st.product_id = ?
          AND sl.status = 'Available'
          AND sl.quantity > 0
          AND (st.expiry_date IS NULL OR st.expiry_date > CURDATE())
        ORDER BY
            CASE WHEN st.expiry_date IS NULL THEN 1 ELSE 0 END,
            st.expiry_date ASC,
            sl.id ASC`,
      [productId],
    );

    const allocations: any[] = [];
    let remaining = requiredQty;

    for (const row of rows) {
      if (remaining <= 0) break;

      const take = Math.min(Number(row.quantity), remaining);
      allocations.push({
        sl_id: row.sl_id,
        stock_id: row.stock_id,
        location_code: row.location_code,
        pallet_seq: row.pallet_seq,
        batch_number: row.batch_number,
        expiry_date: row.expiry_date,
        quantity: take,
        uom: row.uom,
        is_full: take === Number(row.quantity),
      });
      remaining -= take;
    }

    if (remaining > 0) {
      return {
        success: false,
        message: 'Stok tidak cukup. Tersedia: ' + (requiredQty - remaining) + ', Dibutuhkan: ' + requiredQty,
        allocations: [],
      };
    }

    return { success: true, allocations: allocations };
  }

  static async reserveForOutbound(allocations: any[]): Promise<void> {
    for (const a of allocations) {
      const take = a.quantity;
      const slId = a.sl_id;

      const current = await dbScalar('SELECT quantity FROM stock_locations WHERE id = ?', [slId]);

      if (Number(current) <= Number(take)) {
        await dbExec("UPDATE stock_locations SET status='Reserved' WHERE id=?", [slId]);
      } else {
        await dbExec('UPDATE stock_locations SET quantity = quantity - ? WHERE id=?', [take, slId]);

        const orig = await dbExecFirst('SELECT * FROM stock_locations WHERE id=?', [slId]);
        await dbExec(
          `INSERT INTO stock_locations
                (stock_id, location_code, pallet_seq, quantity, uom, is_full_pallet,
                 batch_number, inbound_item_id, status)
                VALUES (?,?,?,?,?,0,?,?,'Reserved')`,
          [
            orig.stock_id, orig.location_code,
            orig.pallet_seq, take, orig.uom,
            orig.batch_number, orig.inbound_item_id,
          ],
        );
      }
    }
  }

  static async deductAfterShip(allocations: any[]): Promise<void> {
    for (const a of allocations) {
      await dbExec("UPDATE stock_locations SET status='Picked' WHERE id=?", [a.sl_id]);
      await dbExec('UPDATE stock SET quantity = GREATEST(0, quantity - ?) WHERE id=?', [a.quantity, a.stock_id]);
    }
  }

  static async releaseReservation(allocations: any[]): Promise<void> {
    for (const a of allocations) {
      await dbExec("UPDATE stock_locations SET status='Available' WHERE id=?", [a.sl_id]);
    }
  }

  static async getPicklistLocations(outboundItemId: number): Promise<any[]> {
    return dbExec(
      `SELECT sl.*, lm.zone
            FROM stock_locations sl
            LEFT JOIN location_master lm ON lm.location_code COLLATE utf8mb4_general_ci = sl.location_code COLLATE utf8mb4_general_ci
            WHERE sl.status IN ('Reserved','Available')
            AND sl.id IN (
                SELECT stock_location_id FROM outbound_items WHERE id = ?
            )
            ORDER BY sl.pallet_seq`,
      [outboundItemId],
    );
  }

  static async getZoneSummary(): Promise<any[]> {
    return dbExec(
      `SELECT lm.zone,
          COUNT(lm.id) as total_locations,
          COUNT(CASE WHEN sl.id IS NOT NULL THEN 1 END) as occupied,
          COUNT(CASE WHEN sl.id IS NULL THEN 1 END) as available
        FROM location_master lm
        LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
            AND sl.status IN ('Available','Reserved')
        WHERE lm.is_active = 1
        GROUP BY lm.zone
        ORDER BY lm.zone`,
    );
  }
}
