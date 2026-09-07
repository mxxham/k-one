import { ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Plus, ClipboardList, Printer, Sparkles, UsersRound } from 'lucide-react';
import { api, webBase } from '@/lib/api';
import { WebBtn } from '@/components/WebBtn';
import { fmtDate, fmtNum, todayISO } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { Card } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';

import Modal from '@/components/Modal';
import Spinner from '@/components/Spinner';
import { PageState } from '@/components/PageState';
import ConfirmButton from '@/components/ConfirmButton';
import { Field, TextInput, Select, Grid } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import LpnLabel, { LpnLabelData } from '@/components/LpnLabel';
import LpnLabelSheet from '@/components/LpnLabelSheet';
import { WorkflowTimeline } from '@/components/inbound/WorkflowTimeline';
import { ItemListCard } from '@/components/inbound/ItemListCard';
import { PalletLocationsTable } from '@/components/inbound/PalletLocationsTable';
import AddItemModal from '@/components/inbound/AddItemModal';
import EditItemModal, { type EditMode } from '@/components/inbound/EditItemModal';
import ReceiveModal from '@/components/inbound/ReceiveModal';
const WORKFLOW_STEPS = ['Draft', 'Dues In', 'Receiving', 'Goods Received', 'ATP', 'Completed'];

interface OrderDetail {
  id: number;
  order_number: string;
  order_date: string;
  carrier_name?: string;
  po_number?: string;
  shipment_no?: string;
  do_number?: string;
  container_no?: string;
  armada_no?: string;
  production_date?: string;
  expected_date?: string;
  status: string;
  notes?: string;
  received_by_name?: string;
  received_date?: string;
  created_by_name?: string;
  od_numbers?: string;
}

export interface PalletLocation {
  id?: number;
  location_code: string;
  pallet_seq: number;
  quantity: number;
  original_quantity?: number;
  status?: string;
}

export interface ItemDetail {
  id: number;
  inbound_order_id?: number;
  od_number?: string;
  so_number?: string;
  product_id?: number;
  product_code: string;
  product_name?: string;
  batch_number?: string;
  location?: string;
  quantity: number;
  uom?: string;
  actual_qty?: number;
  pallet?: number;
  pallet_no?: string;
  manufacture_date?: string;
  exp_date?: string;
  stock_status?: string;
  in_process_status?: string;
  notes?: string;
  pallet_locations?: PalletLocation[];
  cross_dock_outbound_order_id?: number | null;
  cross_dock_order_number?: string;
  cross_dock_order_status?: string;
}

interface DetailUser {
  id: number;
  username: string;
  full_name: string;
  role: string;
}

interface DetailData {
  order: OrderDetail;
  items: ItemDetail[];
  locations?: string[];
  item_pallet_counts?: Record<string, number>;
  users?: DetailUser[];
  products?: any[];
  cross_dock_orders?: any[];
  putaway_task?: PutawayTaskData | null;
}

interface PutawayTaskRow {
  id: number;
  inbound_item_id: number | null;
  product_id: number | null;
  product_code: string | null;
  product_name: string | null;
  batch_number: string | null;
  uom: string | null;
  pallet_seq: number;
  quantity: number;
  suggested_location: string | null;
  actual_location: string | null;
  status: string;
  lpn_code: string | null;
}

interface PutawayTaskData {
  task: {
    id: number;
    task_number: string;
    status: string;
    assigned_to: number | null;
    assigned_name: string | null;
    forklift_operator_id: number | null;
    forklift_operator_name: string | null;
    checklist_partner_id: number | null;
    checklist_partner_name: string | null;
    pallet_count: number;
    done_count: number;
  };
  rows: PutawayTaskRow[];
}

interface AssignableUser {
  id: number;
  username: string;
  full_name: string;
  department: string;
  role: string;
}


interface PalletRow {
  pallet_seq: number;
  location_code: string;
  quantity: string;
  is_full: boolean;
}

function InfoItem({ label, value }: { label: string; value?: ReactNode }) {
  return (
    <div>
      <div className="text-[11px] font-bold uppercase tracking-wide text-gray-400">{label}</div>
      <div className="text-sm font-medium text-gray-800 mt-0.5 break-words">{value || '—'}</div>
    </div>
  );
}

export default function InboundDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  const { canWrite, canAdmin, user } = useAuth();

  const [data, setData] = useState<DetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const [receiveOpen, setReceiveOpen] = useState(false);

  const [editItem, setEditItem] = useState<{ item: ItemDetail; mode: EditMode } | null>(null);

  const [scanErr, setScanErr] = useState<string | null>(null);
  const [scanCode, setScanCode] = useState('');
  const [scanMatchId, setScanMatchId] = useState<number | null>(null);
  const [overrideReason, setOverrideReason] = useState('');

  const [palletItem, setPalletItem] = useState<ItemDetail | null>(null);
  const [palletRows, setPalletRows] = useState<PalletRow[]>([]);
  const [palletSuggesting, setPalletSuggesting] = useState(false);
  const [palletSuggestMsg, setPalletSuggestMsg] = useState('');

  const [addItemOpen, setAddItemOpen] = useState(false);

  // Putaway task on the inbound screen (S49): LPN label print + 2-person team
  // assignment (forklift operator + checklist partner who scans on mobile).
  const [putawayUsers, setPutawayUsers] = useState<AssignableUser[]>([]);
  const [teamModal, setTeamModal] = useState(false);
  const [teamFo, setTeamFo] = useState('');
  const [teamCp, setTeamCp] = useState('');
  const [teamBusy, setTeamBusy] = useState(false);
  const [labelModal, setLabelModal] = useState(false);
  const [label, setLabel] = useState<LpnLabelData | null>(null);
  const [labelBusy, setLabelBusy] = useState(false);
  const [sheetModal, setSheetModal] = useState(false);
  const [sheetLabels, setSheetLabels] = useState<LpnLabelData[]>([]);

  const fetchDetail = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    try {
      const res = await api('inbound', 'detail', { params: { id } });
      setData(res as unknown as DetailData);
    } catch (e: any) {
      if (e.status === 404) {
        toast('error', 'Inbound tidak ditemukan');
        navigate('/inbound');
        return;
      }
      toast('error', e.message || 'Gagal memuat detail inbound');
    } finally {
      setLoading(false);
    }
  }, [id, toast, navigate]);

  useEffect(() => {
    fetchDetail();
  }, [fetchDetail]);

  const loadPutawayUsers = useCallback(async () => {
    try {
      const res = await api('putaway', 'assignable_users');
      setPutawayUsers((res.rows || []) as AssignableUser[]);
    } catch {
      // pickers are optional — ignore failures
    }
  }, []);

  useEffect(() => {
    loadPutawayUsers();
  }, [loadPutawayUsers]);

  const openTeamAssign = () => {
    const pt = data?.putaway_task;
    if (!pt) return;
    setTeamFo(pt.task.forklift_operator_id ? String(pt.task.forklift_operator_id) : '');
    setTeamCp(pt.task.checklist_partner_id ? String(pt.task.checklist_partner_id) : '');
    setTeamModal(true);
  };

  const submitTeamAssign = async () => {
    const pt = data?.putaway_task;
    if (!pt) return;
    if (!teamFo || !teamCp) return toast('error', 'Pilih forklift operator dan checklist partner.');
    try {
      setTeamBusy(true);
      await api('putaway', 'assign_task', {
        body: { id: pt.task.id, forklift_operator_id: Number(teamFo), checklist_partner_id: Number(teamCp) },
      });
      toast('success', 'Tim putaway ditugaskan.');
      setTeamModal(false);
      await fetchDetail();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menugaskan tim');
    } finally {
      setTeamBusy(false);
    }
  };

  const unassignTeam = async () => {
    const pt = data?.putaway_task;
    if (!pt) return;
    try {
      setTeamBusy(true);
      await api('putaway', 'unassign_task', { body: { id: pt.task.id } });
      toast('success', 'Penugasan tim dihapus.');
      await fetchDetail();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menghapus penugasan tim');
    } finally {
      setTeamBusy(false);
    }
  };

  const printLpnLabel = async (rowId: number) => {
    try {
      setLabelBusy(true);
      const res = await api('putaway', 'print_lpn_label', { body: { id: rowId } });
      setLabel(res.label as LpnLabelData);
      setLabelModal(true);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat label LPN');
    } finally {
      setLabelBusy(false);
    }
  };

  const printAllLpnLabels = async () => {
    if (!data?.putaway_task?.rows?.length) return;
    try {
      setLabelBusy(true);
      const labels: LpnLabelData[] = [];
      for (const row of data.putaway_task.rows) {
        const res = await api('putaway', 'print_lpn_label', { body: { id: row.id } });
        labels.push(res.label as LpnLabelData);
      }
      setSheetLabels(labels);
      setSheetModal(true);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat label LPN');
    } finally {
      setLabelBusy(false);
    }
  };

  const runMutation = async (fn: () => Promise<any>, successMsg: string): Promise<boolean> => {
    setBusy(true);
    try {
      await fn();
      toast('success', successMsg);
      await fetchDetail();
      return true;
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
      return false;
    } finally {
      setBusy(false);
    }
  };

  const advanceToDuesIn = async () => {
    await runMutation(
      () => api('inbound', 'advance_status', { body: { id: Number(id), status: 'Dues In' } }),
      'Status berhasil diubah ke Dues In',
    );
  };

  const completeOrder = async () => {
    await runMutation(() => api('inbound', 'complete', { body: { id: Number(id) } }), 'Inbound berhasil dikomplit');
  };

  const repairLedger = async () => {
    await runMutation(() => api('inbound', 'repair_ledger', { body: { id: Number(id) } }), 'Repair ledger berhasil dijalankan');
  };

  const deleteOrder = async () => {
    try {
      await api('inbound', 'delete', { body: { id: Number(id) } });
      toast('success', 'Inbound dihapus');
      navigate('/inbound');
    } catch (e: any) {
      toast('error', e.message || 'Gagal menghapus inbound');
    }
  };

  const updateItemStatus = (item: ItemDetail, status: string) => {
    runMutation(
      () => api('inbound', 'update_item_status', { body: { item_id: item.id, inbound_id: Number(id), status } }),
      `Status item ${status} disimpan`,
    );
  };

  // Phase 2 — scanner-first putaway: a matching scan auto-advances the expected
  // item (next in Dues In/Goods Received that still needs putaway).
  const putawayTarget = data?.items.find((it) => it.in_process_status === 'Dues In' || it.in_process_status === 'Goods Received') ?? null;
  const scanNextStatus = putawayTarget?.in_process_status === 'Dues In' ? 'Goods Received' : 'ATP';

  const handleScan = async (code: string) => {
    setScanErr(null);
    setScanMatchId(null);
    setOverrideReason('');
    let res: any;
    try {
      res = await api('stock', 'scan', { params: { code } });
    } catch (err: any) {
      setScanErr(err.message || 'Gagal membaca kode');
      return;
    }
    if (!res?.found) {
      setScanErr(`Kode '${code}' tidak dikenali (product tidak ditemukan).`);
      return;
    }
    const scannedCode = res.product?.product_code || code;
    const expectedCode = putawayTarget?.product_code || '';
    if (putawayTarget && expectedCode && scannedCode === expectedCode) {
      setScanMatchId(putawayTarget.id);
      const next = putawayTarget.in_process_status === 'Dues In' ? 'Goods Received' : 'ATP';

      let orderStatus = order.status;
      if (orderStatus === 'Draft') {
        await api('inbound', 'advance_status', { body: { id: Number(id), status: 'Dues In' } });
        orderStatus = 'Dues In';
      }
      if (orderStatus === 'Dues In' && user?.id) {
        await api('inbound', 'advance_status', {
          body: { id: Number(id), status: 'Receiving', received_by_id: user.id, received_date: todayISO() },
        });
      }

      await runMutation(
        () =>
          api('inbound', 'update_item_status', {
            body: { item_id: putawayTarget.id, inbound_id: Number(id), status: next },
          }),
        `${putawayTarget.product_code || scannedCode} → ${next}`,
      );

      const fresh: any = await api('inbound', 'detail', { params: { id } });
      const freshItems: ItemDetail[] = fresh?.items ?? [];
      const allDone = freshItems.length > 0 && freshItems.every((it) => ['ATP', 'Unserviceable'].includes(it.in_process_status ?? ''));
      if (allDone) {
        await api('inbound', 'complete', { body: { id: Number(id) } });
        await fetchDetail();
        toast('success', 'Inbound otomatis dikomplit');
      }
    } else {
      setScanCode(code);
      setScanErr(
        `Kode '${scannedCode}' tidak cocok dengan item yang diproses${expectedCode ? ` '${expectedCode}'` : ' (tidak ada item yang perlu putaway)'}.`,
      );
    }
  };

  const handleOverride = async () => {
    if (!overrideReason.trim()) {
      toast('error', 'Alasan override wajib diisi');
      return;
    }
    try {
      await api('stock', 'scan_override', {
        method: 'POST',
        body: { code: scanCode, reason: overrideReason.trim(), context: `inbound:${order?.order_number || ''}` },
      });
      setScanErr(null);
      setOverrideReason('');
      toast('success', 'Override dicatat');
    } catch (err: any) {
      toast('error', err.message || 'Gagal menyimpan override');
    }
  };

  const openPalletModal = async (item: ItemDetail) => {
    const existing = item.pallet_locations && item.pallet_locations.length ? item.pallet_locations : [{ pallet_seq: 1, location_code: '', quantity: 0 }];
    setPalletRows(
      existing.map((r) => ({
        pallet_seq: r.pallet_seq,
        location_code: r.location_code || '',
        quantity: String(r.quantity ?? ''),
        is_full: true,
      })),
    );
    setPalletItem(item);
    setPalletSuggestMsg('');
    const hasSaved = existing.some((r) => r.location_code && String(r.location_code).trim());
    if (!hasSaved && !item.cross_dock_outbound_order_id && item.product_id) {
      setPalletSuggesting(true);
      try {
        const res: any = await api('putaway', 'recommend', {
          params: { product_id: item.product_id, quantity: item.quantity, uom: item.uom },
        });
        if (res?.pallets?.length) {
          setPalletRows(
            res.pallets.map((p: any) => ({
              pallet_seq: p.pallet_seq,
              location_code: p.location_code || '',
              quantity: String(p.quantity ?? ''),
              is_full: p.is_full !== false,
            })),
          );
        }
        setPalletSuggestMsg(putawayMsg(res, `Saran lokasi putaway: ${res?.pallets?.length ?? 0} pallet.`));
      } catch (e: any) {
        setPalletSuggestMsg(e.message || 'Gagal mendapat saran lokasi putaway.');
      } finally {
        setPalletSuggesting(false);
      }
    }
  };
  const putawayMsg = (res: any, fallback: string): string => {
    if (res?.message) return res.message;
    const upp = Number(res?.uom_per_pallet ?? 0);
    const n = res?.pallets?.length ?? 0;
    if (!upp || n === 0) return fallback;
    const full = res.pallets.filter((p: any) => p.is_full).length;
    const rem = res.pallets.find((p: any) => !p.is_full)?.quantity;
    const parts: string[] = [];
    if (full > 0) parts.push(`${full} pallet penuh @ ${upp} pcs`);
    if (rem && Number(rem) > 0) parts.push(`sisa ${rem} pcs ke pick-face`);
    return `Saran putaway: ${parts.join(', ')} (${n} lokasi).`;
  };
  const suggestPallet = async () => {
    if (!palletItem?.product_id) return;
    setPalletSuggesting(true);
    setPalletSuggestMsg('');
    try {
      const res: any = await api('putaway', 'recommend', {
        params: { product_id: palletItem.product_id, quantity: palletItem.quantity, uom: palletItem.uom },
      });
      if (res?.pallets?.length) {
        setPalletRows(
          res.pallets.map((p: any) => ({
            pallet_seq: p.pallet_seq,
            location_code: p.location_code || '',
            quantity: String(p.quantity ?? ''),
            is_full: p.is_full !== false,
          })),
        );
      }
      setPalletSuggestMsg(putawayMsg(res, 'Saran lokasi putaway diperbarui.'));
    } catch (e: any) {
      setPalletSuggestMsg(e.message || 'Gagal mendapat saran lokasi putaway.');
    } finally {
      setPalletSuggesting(false);
    }
  };
  const updatePalletRow = (i: number, patch: Partial<PalletRow>) =>
    setPalletRows((arr) => arr.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  const addPalletRow = () => setPalletRows((arr) => [...arr, { pallet_seq: arr.length + 1, location_code: '', quantity: '', is_full: true }]);
  const removePalletRow = (i: number) => setPalletRows((arr) => arr.filter((_, idx) => idx !== i));
  const savePallet = async () => {
    if (!palletItem) return;
    const ok = await runMutation(
      () =>
        api('inbound', 'save_pallet_locations', {
          body: {
            item_id: palletItem.id,
            inbound_id: Number(id),
            pallet_locations: palletRows.map((r, i) => ({
              location_code: r.location_code.trim(),
              pallet_seq: r.pallet_seq || i + 1,
              quantity: Number(r.quantity) || 0,
              is_full: r.is_full,
            })),
          },
        }),
      'Pallet locations disimpan',
    );
    if (ok) setPalletItem(null);
  };

  const deleteItem = (item: ItemDetail) => {
    runMutation(
      () => api('inbound', 'delete_item', { body: { item_id: item.id, inbound_id: Number(id) } }),
      'Item dihapus',
    );
  };

  if (!data) return <PageState empty={true} onRetry={fetchDetail} emptyMessage="Data tidak ditemukan"><div /></PageState>;

  const order = data.order;
  const statusNorm = (s: string) => (s === 'Good Received' ? 'Goods Received' : s);
  const orderIdx = WORKFLOW_STEPS.indexOf(statusNorm(order.status));
  const wfItems = data.items ?? [];
  const allDone = wfItems.length > 0 && wfItems.every((it) => ['ATP', 'Unserviceable'].includes(it.in_process_status ?? ''));
  const anyReceived = wfItems.some((it) => ['Goods Received', 'ATP'].includes(it.in_process_status ?? ''));
  const itemIdx = allDone ? WORKFLOW_STEPS.indexOf('ATP') : anyReceived ? WORKFLOW_STEPS.indexOf('Goods Received') : -1;
  const activeIdx = orderIdx >= 0 ? Math.max(orderIdx, itemIdx) : orderIdx;
  const isDone = order.status === 'Completed' || order.status === 'Cancelled';
  const editable = canWrite && !isDone;

  const pt = data.putaway_task ?? null;
  const ptEditable =
    canWrite && !isDone && !!pt && (pt.task.status === 'Open' || pt.task.status === 'Pending' || pt.task.status === 'In Progress');
  const hasTeam = !!(pt && (pt.task.forklift_operator_id || pt.task.checklist_partner_id));

  const palletCount = (item: ItemDetail) => data.item_pallet_counts?.[String(item.id)] ?? item.pallet ?? 0;

  // Each item with saved pallet locations renders as ONE row per pallet/location
  // (same product repeated) — mirroring the spreadsheet's per-bin item rows.
  const displayRows: { item: ItemDetail; pallet: PalletLocation | null }[] = data.items.flatMap((item): { item: ItemDetail; pallet: PalletLocation | null }[] => {
    const locs = (item.pallet_locations ?? []).filter((l) => l.location_code && String(l.location_code).trim());
    if (locs.length === 0) return [{ item, pallet: null }];
    return locs.map((pallet) => ({ item, pallet }));
  });

const headerActions = (
    <div className="flex items-center gap-2 flex-wrap">
      <Link
        to="/inbound"
        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/25 border border-white/40 text-white text-sm font-semibold hover:bg-white/35"
      >
        <ArrowLeft className="w-4 h-4" /> Back
      </Link>
      <WebBtn href={`${webBase()}/print_inbound.php?id=${order.id}`} label="Receipt" icon={<Printer className="w-4 h-4" />} />
      <WebBtn href={`${webBase()}/putaway_sheet.php?id=${order.id}`} label="Putaway" icon={<ClipboardList className="w-4 h-4" />} />
      {editable && order.status === 'Draft' && (
        <button
          onClick={advanceToDuesIn}
          disabled={busy}
          className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white text-brand-700 text-sm font-semibold hover:bg-brand-50 disabled:opacity-60"
        >
          Advance to Dues In
        </button>
      )}
      {editable && order.status === 'Dues In' && (
        <button
          onClick={() => setReceiveOpen(true)}
          disabled={busy}
          className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white text-brand-700 text-sm font-semibold hover:bg-brand-50 disabled:opacity-60"
        >
          Advance to Receiving
        </button>
      )}
      {editable && (
        <button
          onClick={completeOrder}
          disabled={busy}
          className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white text-brand-700 text-sm font-semibold hover:bg-brand-50 disabled:opacity-60"
        >
          Complete
        </button>
      )}
      {editable && canAdmin && (
        <button
          onClick={repairLedger}
          disabled={busy}
          className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25 disabled:opacity-60"
        >
          Repair Ledger
        </button>
      )}
      {editable && (
        <ConfirmButton label="Delete Order" confirmText="Hapus order inbound ini?" onConfirm={deleteOrder} disabled={busy} />
      )}
    </div>
  );

  return (
    <PageState loading={loading} onRetry={fetchDetail} emptyMessage="Data tidak ditemukan">
    <div>
      <PageHeader
        title={order.order_number}
        subtitle={`Order date: ${fmtDate(order.order_date)}`}
        actions={headerActions}
      />

      <WorkflowTimeline activeIdx={activeIdx} status={order.status} />

      <Card title="Order Information">
        <Grid cols={4}>
          <InfoItem label="Carrier" value={order.carrier_name} />
          <InfoItem label="PO Number" value={order.po_number} />
          <InfoItem label="Shipment No" value={order.shipment_no} />
          <InfoItem label="DO Number" value={order.do_number} />
          <InfoItem label="Container No" value={order.container_no} />
          <InfoItem label="Armada No" value={order.armada_no} />
          <InfoItem label="Production Date" value={fmtDate(order.production_date)} />
          <InfoItem label="Expected Date" value={fmtDate(order.expected_date)} />
          <InfoItem label="Received By" value={order.received_by_name} />
          <InfoItem label="Received Date" value={fmtDate(order.received_date)} />
          <InfoItem label="Created By" value={order.created_by_name} />
          <InfoItem label="OD Numbers" value={order.od_numbers} />
        </Grid>
        <div className="mt-4">
          <InfoItem label="Notes" value={order.notes} />
        </div>
      </Card>

      <ItemListCard
        items={data.items}
        displayRows={displayRows}
        editable={editable}
        busy={busy}
        scanErr={scanErr}
        putawayTarget={putawayTarget}
        scanNextStatus={scanNextStatus}
        overrideReason={overrideReason}
        scanMatchId={scanMatchId}
        handleScan={handleScan}
        handleOverride={handleOverride}
        setOverrideReason={setOverrideReason}
        setScanErr={setScanErr}
        openAddItem={() => setAddItemOpen(true)}
        openEditQty={(item) => setEditItem({ item, mode: 'quantity' })}
        openEditDates={(item) => setEditItem({ item, mode: 'dates' })}
        openPalletNo={(item) => setEditItem({ item, mode: 'pallet_no' })}
        openLoc={(item) => setEditItem({ item, mode: 'location' })}
        openPalletModal={openPalletModal}
        updateItemStatus={updateItemStatus}
        deleteItem={deleteItem}
        palletCount={palletCount}
      />

      {data.putaway_task && (
        <Card
          title={`Putaway Task — ${data.putaway_task.task.task_number}`}
          actions={
            ptEditable ? (
              <div className="flex items-center gap-2">
                {hasTeam ? (
                  <>
                    <button
                      onClick={openTeamAssign}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-600 text-white hover:bg-brand-700"
                    >
                      <UsersRound className="w-3.5 h-3.5" /> Ubah Tim
                    </button>
                    <ConfirmButton label="Lepas Tim" confirmText="Hapus penugasan tim?" onConfirm={unassignTeam} variant="ghost" />
                  </>
                ) : (
                  <button
                    onClick={openTeamAssign}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-600 text-white hover:bg-brand-700"
                  >
                    <UsersRound className="w-3.5 h-3.5" /> Tugaskan Tim
                  </button>
                )}
              </div>
            ) : undefined
          }
        >
          <div className="flex items-center gap-3 flex-wrap mb-3 text-sm">
            <StatusBadge status={data.putaway_task.task.status} />
            <span className="text-gray-600">
              <span className="font-semibold">{data.putaway_task.task.done_count}</span>/
              {data.putaway_task.task.pallet_count} pallet selesai
            </span>
            <span className="text-gray-600">
              Forklift:{' '}
              <span className="font-semibold">{data.putaway_task.task.forklift_operator_name || 'Belum ditugaskan'}</span>
            </span>
            <span className="text-gray-600">
              Checklist (scan):{' '}
              <span className="font-semibold">{data.putaway_task.task.checklist_partner_name || 'Belum ditugaskan'}</span>
            </span>
          </div>
          <div className="flex items-center gap-2 mb-3">
            <button
              onClick={printAllLpnLabels}
              disabled={labelBusy || !data.putaway_task.rows.length}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-50"
            >
              <Printer className="w-3.5 h-3.5" /> Cetak Sheet LPN ({data.putaway_task.rows.length} label)
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-gray-500">
                  <th className="px-3 py-2">LPN</th>
                  <th className="px-3 py-2">Produk</th>
                  <th className="px-3 py-2">Batch</th>
                  <th className="px-3 py-2 text-right">Qty</th>
                  <th className="px-3 py-2">Lokasi Tujuan</th>
                  <th className="px-3 py-2">Lokasi Aktual</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody>
                {data.putaway_task.rows.map((row) => (
                  <tr key={row.id} className="border-t border-gray-100">
                    <td className="px-3 py-2 font-mono text-xs font-semibold">{row.lpn_code || '—'}</td>
                    <td className="px-3 py-2">
                      <div className="font-semibold">{row.product_code || '—'}</div>
                      <div className="text-[11px] text-gray-500">{row.product_name || ''}</div>
                    </td>
                    <td className="px-3 py-2 text-gray-600">{row.batch_number || '—'}</td>
                    <td className="px-3 py-2 text-right font-medium">
                      {fmtNum(row.quantity)} {row.uom || ''}
                    </td>
                    <td className="px-3 py-2 font-mono text-xs text-gray-700">{row.suggested_location || '—'}</td>
                    <td className="px-3 py-2 font-mono text-xs font-semibold text-brand-700">{row.actual_location || '—'}</td>
                    <td className="px-3 py-2">
                      <StatusBadge status={row.status} />
                    </td>
                    <td className="px-3 py-2 text-right">
                      {canWrite && (
                        <button
                          onClick={() => printLpnLabel(row.id)}
                          disabled={labelBusy}
                          className="p-1.5 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-40"
                          title="Cetak label LPN"
                        >
                          <Printer className="w-3.5 h-3.5" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <Modal open={teamModal} onClose={() => setTeamModal(false)} title="Tugaskan Tim Putaway" size="sm">
        <div className="space-y-4">
          <Field label="Forklift Operator" required>
            <Select value={teamFo} onChange={(e) => setTeamFo(e.target.value)}>
              <option value="">— Pilih operator —</option>
              {putawayUsers.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name} ({u.username}){u.department !== 'all' ? ` · ${u.department}` : ''}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Checklist Partner (scan)" required>
            <Select value={teamCp} onChange={(e) => setTeamCp(e.target.value)}>
              <option value="">— Pilih partner —</option>
              {putawayUsers.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name} ({u.username}){u.department !== 'all' ? ` · ${u.department}` : ''}
                </option>
              ))}
            </Select>
          </Field>
          <div className="flex justify-end gap-2 pt-1">
            <button
              onClick={() => setTeamModal(false)}
              className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
            >
              Batal
            </button>
            <button
              onClick={submitTeamAssign}
              disabled={teamBusy}
              className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
            >
              {teamBusy ? 'Menyimpan…' : 'Tugaskan Tim'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal open={labelModal} onClose={() => setLabelModal(false)} title="Label LPN" size="sm">
        {labelBusy ? <Spinner label="Memuat label…" /> : label ? <LpnLabel label={label} /> : null}
      </Modal>
      <Modal open={sheetModal} onClose={() => setSheetModal(false)} title="Sheet Label LPN" size="lg">
        {labelBusy ? <Spinner label="Memuat label…" /> : sheetLabels.length > 0 ? <LpnLabelSheet labels={sheetLabels} /> : null}
      </Modal>
      <ReceiveModal
        open={receiveOpen}
        onClose={() => setReceiveOpen(false)}
        inboundId={Number(id)}
        users={data.users}
        onSuccess={fetchDetail}
      />

      <EditItemModal
        open={!!editItem}
        onClose={() => setEditItem(null)}
        item={editItem?.item ?? null}
        mode={editItem?.mode ?? 'quantity'}
        inboundId={Number(id)}
        locations={data.locations}
        onSuccess={fetchDetail}
      />

      <Modal open={!!palletItem} onClose={() => setPalletItem(null)} title="Manage Pallet Locations" size="lg">
        {palletItem && (
          <div className="space-y-4">
            <div className="text-sm text-gray-500">
              <span className="font-semibold text-gray-700">{palletItem.product_code}</span> — {palletItem.product_name}
            </div>
            <PalletLocationsTable
              rows={palletRows}
              onUpdate={updatePalletRow}
              onRemove={removePalletRow}
            />
            <div className="flex flex-wrap items-center gap-2">
              <button
                type="button"
                onClick={addPalletRow}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-50 text-brand-700 border border-brand-200 hover:bg-brand-100"
              >
                <Plus className="w-3.5 h-3.5" /> Add Row
              </button>
              <button
                type="button"
                onClick={suggestPallet}
                disabled={palletSuggesting || !palletItem?.product_id}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-violet-50 text-violet-700 border border-violet-200 hover:bg-violet-100 disabled:opacity-50"
              >
                <Sparkles className="w-3.5 h-3.5" /> {palletSuggesting ? 'Mencari...' : 'Saran Lokasi (Putaway)'}
              </button>
            </div>
            {palletSuggestMsg && <div className="text-xs text-gray-500">{palletSuggestMsg}</div>}
            <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
              <button onClick={() => setPalletItem(null)} className="px-4 py-2 text-sm rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 font-semibold">
                Batal
              </button>
              <button
                onClick={savePallet}
                disabled={busy}
                className="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white hover:bg-brand-700 font-semibold disabled:opacity-60"
              >
                {busy ? 'Menyimpan...' : 'Simpan'}
              </button>
            </div>
          </div>
        )}
      </Modal>

      <AddItemModal
        open={addItemOpen}
        onClose={() => setAddItemOpen(false)}
        inboundId={Number(id)}
        crossDockOrders={data.cross_dock_orders}
        onSuccess={fetchDetail}
      />
    </div>
    </PageState>
  );
}
