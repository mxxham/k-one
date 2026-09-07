import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { useConfirmDialog } from './useConfirmDialog';

function Harness() {
  const { confirm, alert, ConfirmDialog } = useConfirmDialog();
  return (
    <>
      <button data-testid="confirm-btn" onClick={() => confirm('Delete this?', 'Confirm Action')}>
        Open Confirm
      </button>
      <button data-testid="alert-btn" onClick={() => alert('Saved!', 'Success')}>
        Open Alert
      </button>
      <ConfirmDialog />
    </>
  );
}

let lastResult: boolean | undefined;
let alertResolved = false;

function HarnessWithResolver() {
  const { confirm, alert, ConfirmDialog } = useConfirmDialog();
  return (
    <>
      <button
        data-testid="confirm-true"
        onClick={async () => {
          lastResult = await confirm('Proceed?');
        }}
      >
        Confirm True
      </button>
      <button
        data-testid="confirm-false"
        onClick={async () => {
          lastResult = await confirm('Proceed?');
        }}
      >
        Confirm False
      </button>
      <button
        data-testid="alert-resolve"
        onClick={async () => {
          await alert('Done');
          alertResolved = true;
        }}
      >
        Alert Resolve
      </button>
      <ConfirmDialog />
    </>
  );
}

function HarnessDefaultTitles() {
  const { confirm, alert, ConfirmDialog } = useConfirmDialog();
  return (
    <>
      <button data-testid="no-title-confirm" onClick={() => confirm('Are you sure?')}>
        Open Confirm Default
      </button>
      <button data-testid="no-title-alert" onClick={() => alert('Noted.')}>
        Open Alert Default
      </button>
      <ConfirmDialog />
    </>
  );
}

function clickButton(name: string) {
  return fireEvent.click(screen.getByRole('button', { name }));
}

beforeEach(() => {
  lastResult = undefined;
  alertResolved = false;
});

describe('useConfirmDialog', () => {
  describe('confirm()', () => {
    it('does not render the dialog before confirm is called', () => {
      render(<Harness />);
      expect(screen.queryByText('Delete this?')).not.toBeInTheDocument();
    });

    it('renders the dialog with message and title when confirm is called', async () => {
      render(<Harness />);
      await act(async () => {
        clickButton('Open Confirm');
      });
      expect(screen.getByText('Delete this?')).toBeInTheDocument();
      expect(screen.getByText('Confirm Action')).toBeInTheDocument();
    });

    it('renders Confirm and Cancel buttons in confirm mode', async () => {
      render(<Harness />);
      await act(async () => {
        clickButton('Open Confirm');
      });
      expect(screen.getByRole('button', { name: 'Confirm' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('resolves true when Confirm button is clicked', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Confirm True');
      });
      await act(async () => {
        clickButton('Confirm');
      });
      expect(lastResult).toBe(true);
    });

    it('resolves false when Cancel button is clicked', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Confirm False');
      });
      await act(async () => {
        clickButton('Cancel');
      });
      expect(lastResult).toBe(false);
    });

    it('resolves false when Escape key is pressed', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Confirm False');
      });
      await act(async () => {
        fireEvent.keyDown(document, { key: 'Escape' });
      });
      expect(lastResult).toBe(false);
    });
  });

  describe('alert()', () => {
    it('renders the dialog with message and title', async () => {
      render(<Harness />);
      await act(async () => {
        clickButton('Open Alert');
      });
      expect(screen.getByText('Saved!')).toBeInTheDocument();
      expect(screen.getByText('Success')).toBeInTheDocument();
    });

    it('renders only an OK button (no Cancel)', async () => {
      render(<Harness />);
      await act(async () => {
        clickButton('Open Alert');
      });
      expect(screen.getByRole('button', { name: 'OK' })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });

    it('resolves after OK is clicked', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Alert Resolve');
      });
      await act(async () => {
        clickButton('OK');
      });
      expect(alertResolved).toBe(true);
    });

    it('resolves after Escape is pressed', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Alert Resolve');
      });
      await act(async () => {
        fireEvent.keyDown(document, { key: 'Escape' });
      });
      expect(alertResolved).toBe(true);
    });
  });

  describe('dialog lifecycle', () => {
    it('closes the dialog after confirm resolves', async () => {
      render(<HarnessWithResolver />);
      await act(async () => {
        clickButton('Confirm True');
      });
      expect(screen.getByText('Proceed?')).toBeInTheDocument();
      await act(async () => {
        clickButton('Confirm');
      });
      expect(screen.queryByText('Proceed?')).not.toBeInTheDocument();
    });

    it('can open confirm again after closing', async () => {
      render(<HarnessWithResolver />);

      // First open + close
      await act(async () => {
        clickButton('Confirm True');
      });
      await act(async () => {
        clickButton('Confirm');
      });

      // Second open
      await act(async () => {
        clickButton('Confirm True');
      });
      expect(screen.getByText('Proceed?')).toBeInTheDocument();
    });

    it('uses default titles when none provided', async () => {
      render(<HarnessDefaultTitles />);

      await act(async () => {
        clickButton('Open Confirm Default');
      });
      expect(screen.getAllByText('Confirm').length).toBeGreaterThanOrEqual(1);

      await act(async () => {
        clickButton('Cancel');
      });

      await act(async () => {
        clickButton('Open Alert Default');
      });
      expect(screen.getByText('Alert')).toBeInTheDocument();
    });
  });
});
