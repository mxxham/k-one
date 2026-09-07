import { useState, useRef, useCallback } from 'react';
import Modal from '@/components/Modal';

type DialogMode = 'confirm' | 'alert' | null;

interface DialogState {
  mode: DialogMode;
  message: string;
  title: string;
}

/**
 * A hook that replaces native `alert()` and `confirm()` with a modal-based
 * implementation. Native dialogs break on iOS/Android WebView and block the
 * main thread.
 *
 * @example
 * ```tsx
 * function MyComponent() {
 *   const { confirm, alert, ConfirmDialog } = useConfirmDialog();
 *
 *   const handleDelete = async () => {
 *     const ok = await confirm('Are you sure you want to delete this item?');
 *     if (ok) {
 *       await deleteItem();
 *       await alert('Item deleted successfully.');
 *     }
 *   };
 *
 *   return (
 *     <>
 *       <button onClick={handleDelete}>Delete</button>
 *       <ConfirmDialog />
 *     </>
 *   );
 * }
 * ```
 */
export function useConfirmDialog() {
  const [dialog, setDialog] = useState<DialogState | null>(null);
  const resolveRef = useRef<((value: boolean) => void) | null>(null);

  const close = useCallback(() => {
    setDialog(null);
    resolveRef.current = null;
  }, []);

  /**
   * Show a confirmation dialog. Resolves to `true` when the user confirms,
   * or `false` when the user cancels / presses Escape.
   */
  const confirm = useCallback(
    (message: string, title = 'Confirm'): Promise<boolean> => {
      return new Promise<boolean>((resolve) => {
        resolveRef.current = resolve;
        setDialog({ mode: 'confirm', message, title });
      });
    },
    [],
  );

  /**
   * Show an alert dialog. Resolves after the user clicks OK or presses Escape.
   */
  const alert = useCallback(
    (message: string, title = 'Alert'): Promise<void> => {
      return new Promise<void>((resolve) => {
        resolveRef.current = () => resolve();
        setDialog({ mode: 'alert', message, title });
      });
    },
    [],
  );

  const handleConfirm = useCallback(() => {
    resolveRef.current?.(true);
    close();
  }, [close]);

  const handleCancel = useCallback(() => {
    resolveRef.current?.(false);
    close();
  }, [close]);

  const handleOk = useCallback(() => {
    resolveRef.current?.(false); // alert resolve passes void; (false) is ignored
    close();
  }, [close]);

  /** Render this component at the root of your page tree. */
  const ConfirmDialog = useCallback(
    () => (
      <Modal
        open={dialog !== null}
        onClose={dialog?.mode === 'confirm' ? handleCancel : handleOk}
        title={dialog?.title ?? ''}
        size="sm"
      >
        <p className="text-gray-700 text-sm leading-relaxed">{dialog?.message}</p>
        <div className="flex justify-end gap-3 mt-5">
          {dialog?.mode === 'confirm' && (
            <button
              onClick={handleCancel}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
            >
              Cancel
            </button>
          )}
          <button
            onClick={dialog?.mode === 'confirm' ? handleConfirm : handleOk}
            className="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 transition-colors"
          >
            {dialog?.mode === 'confirm' ? 'Confirm' : 'OK'}
          </button>
        </div>
      </Modal>
    ),
    [dialog, handleConfirm, handleCancel, handleOk],
  );

  return { confirm, alert, ConfirmDialog };
}
