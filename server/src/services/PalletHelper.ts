import { dbExecFirst, dbScalar } from '../db';

export class PalletHelper {
  static UOM_PALLET: Record<string, any> = {
    'Drum': 4,
    'Carton': [36, 44, 48],
    'Pail': 24,
  };

  static DEFAULT_PALLET: Record<string, number> = {
    'Drum': 4,
    'Carton': 44,
    'Pail': 24,
  };

  static calculatePallet(quantity: number, uom = 'Drum', customPalletQty: number | null = null): any {
    const palletCapacity = customPalletQty ?? PalletHelper.DEFAULT_PALLET[uom] ?? 4;

    const pallets = Math.floor(quantity / palletCapacity);
    const remainder = quantity % palletCapacity;

    return {
      units: quantity,
      pallets,
      pallet_decimal: Math.round(quantity / palletCapacity * 100) / 100,
      remainder,
      pallet_capacity: palletCapacity,
    };
  }

  static palletToUnits(pallets: number, uom = 'Drum', customPalletQty: number | null = null): number {
    const palletCapacity = customPalletQty ?? PalletHelper.DEFAULT_PALLET[uom] ?? 4;
    return pallets * palletCapacity;
  }

  static getPalletCapacity(uom: string): number {
    return PalletHelper.DEFAULT_PALLET[uom] ?? 4;
  }

  static async validateQuantity(productId: number, quantity: number): Promise<any> {
    const product = await dbExecFirst('SELECT max_sku_qty, max_trans_qty FROM products WHERE id = ?', [productId]);

    if (!product) {
      return { valid: false, message: 'Product not found' };
    }

    const maxSku = product.max_sku_qty ?? 44;
    const maxTrans = product.max_trans_qty ?? 80;

    if (quantity > maxTrans) {
      return {
        valid: false,
        message: `Quantity exceeds maximum transaction limit (${maxTrans}). Maximum allowed: ${maxTrans} units`,
      };
    }

    const currentStock = await dbScalar(
      "SELECT COALESCE(SUM(quantity), 0) as current_stock FROM stock WHERE product_id = ? AND stock_status = 'Available'",
      [productId],
    );

    if ((Number(currentStock) + quantity) > maxSku) {
      return {
        valid: false,
        message: `Total stock would exceed maximum SKU limit (${maxSku}). Current: ${currentStock}, Adding: ${quantity}, Max allowed: ${maxSku}`,
      };
    }

    return { valid: true };
  }

  static calculateExpiryDate(productionDate: string, years = 4): string {
    const date = new Date(productionDate);
    date.setFullYear(date.getFullYear() + years);
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  static getExpiryInfo(expiryDate: any): any {
    if (!expiryDate) {
      return { days: null, months: null, text: 'No expiry', is_critical: false };
    }

    const expiryStr = String(expiryDate);
    const expiry = new Date(/^\d{4}-\d{2}-\d{2}$/.test(expiryStr) ? expiryStr + 'T00:00:00' : expiryStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diffMs = expiry.getTime() - today.getTime();

    const days = Math.round(Math.abs(diffMs) / 86400000);
    let months = (expiry.getFullYear() - today.getFullYear()) * 12 + (expiry.getMonth() - today.getMonth());
    let remDays = expiry.getDate() - today.getDate();
    if (remDays < 0) {
      months -= 1;
      remDays += new Date(expiry.getFullYear(), expiry.getMonth(), 0).getDate();
    }
    if (months < 0) months = 0;
    if (remDays < 0) remDays = 0;

    const isExpired = diffMs < 0;
    const isCritical = isExpired ? true : (days <= 120);

    const text = isExpired ? `Expired ${days} days ago` : `${months}m ${remDays}d left`;

    return {
      days,
      months,
      remaining_days: isExpired ? -days : days,
      text,
      is_critical: isCritical,
      is_expired: isExpired,
    };
  }

  static generateLocations(totalPallets: number, baseLocation = 'SUB50'): any[] {
    const locations: any[] = [];
    for (let i = 1; i <= totalPallets; i++) {
      locations.push({
        pallet_number: i,
        location: baseLocation + '-P' + String(i).padStart(2, '0'),
        is_full: true,
      });
    }
    return locations;
  }

  static async getProductUOMInfo(productId: number): Promise<any> {
    const product = await dbExecFirst('SELECT uom_type, uom_per_pallet, liters_per_unit FROM products WHERE id = ?', [productId]);

    if (!product) {
      return {
        uom_type: 'Drum',
        uom_per_pallet: 4,
        liters_per_unit: 209,
      };
    }

    return {
      uom_type: product.uom_type ?? 'Drum',
      uom_per_pallet: product.uom_per_pallet ?? 4,
      liters_per_unit: product.liters_per_unit ?? 209,
    };
  }

  static calculateLiters(quantity: number, litersPerUnit = 209): number {
    return quantity * litersPerUnit;
  }
}
