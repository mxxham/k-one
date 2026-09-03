import { handleAuth } from './auth';
import { handleBinTransfer } from './bintransfer';
import { handleDashboard } from './dashboard';
import { handleImport } from './import';
import { handleInbound } from './inbound';
import { handleLedger } from './ledger';
import { handleProducts, handleCustomers, handleLocations, handleUsers } from './master';
import { handleOutbound } from './outbound';
import { handlePicklist } from './picklist';
import { handleReplenishment } from './replenishment';
import { handleReport, handleActivityLog } from './reports';
import { handleStock } from './stock';
import { handleStockTake } from './stocktake';
import { handleSystem } from './system';

export type ActionHandler = (action: string) => Promise<void>;

export const handlers: Record<string, ActionHandler> = {
  auth: handleAuth,
  bintransfer: handleBinTransfer,
  dashboard: handleDashboard,
  inbound: handleInbound,
  import: handleImport,
  ledger: handleLedger,
  products: handleProducts,
  customers: handleCustomers,
  locations: handleLocations,
  users: handleUsers,
  outbound: handleOutbound,
  picklist: handlePicklist,
  replenishment: handleReplenishment,
  report: handleReport,
  activitylog: handleActivityLog,
  stock: handleStock,
  stocktake: handleStockTake,
  system: handleSystem,
};
