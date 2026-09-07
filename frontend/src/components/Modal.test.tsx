import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { useState, useRef } from 'react';
import Modal from './Modal';

function ModalHarness() {
  const [open, setOpen] = useState(true);
  return (
    <Modal open={open} onClose={() => setOpen(false)} title="Test Modal" size="sm">
      <p>modal body</p>
    </Modal>
  );
}

function ModalWithFocusableContent() {
  const [open, setOpen] = useState(true);
  const inputRef = useRef<HTMLInputElement>(null);
  return (
    <div>
      <button data-testid="trigger">trigger</button>
      <Modal open={open} onClose={() => setOpen(false)} title="Focus Trap">
        <input data-testid="first-input" defaultValue="a" />
        <button data-testid="mid-btn">mid</button>
        <input data-testid="last-input" defaultValue="b" />
      </Modal>
    </div>
  );
}

describe('Modal', () => {
  it('renders nothing when closed', () => {
    render(
      <Modal open={false} onClose={() => {}} title="Hidden">
        <p>body</p>
      </Modal>,
    );
    expect(screen.queryByText('Hidden')).not.toBeInTheDocument();
  });

  it('renders the title and children when open', () => {
    render(
      <Modal open onClose={() => {}} title="Visible">
        <p>body</p>
      </Modal>,
    );
    expect(screen.getByText('Visible')).toBeInTheDocument();
    expect(screen.getByText('body')).toBeInTheDocument();
  });

  it('closes on Escape', () => {
    render(<ModalHarness />);
    expect(screen.getByText('Test Modal')).toBeInTheDocument();
    act(() => {
      fireEvent.keyDown(document, { key: 'Escape' });
    });
    expect(screen.queryByText('Test Modal')).not.toBeInTheDocument();
  });

  it('closes via the X button', () => {
    render(<ModalHarness />);
    fireEvent.click(screen.getByRole('button'));
    expect(screen.queryByText('Test Modal')).not.toBeInTheDocument();
  });

  it('calls onClose when the close button is pressed', () => {
    const onClose = vi.fn();
    render(
      <Modal open onClose={onClose} title="T">
        <p>body</p>
      </Modal>,
    );
    fireEvent.click(screen.getByRole('button'));
    expect(onClose).toHaveBeenCalled();
  });

  it('locks body scroll when open', () => {
    render(
      <Modal open onClose={() => {}} title="Scroll Lock">
        <p>body</p>
      </Modal>,
    );
    expect(document.body.style.overflow).toBe('hidden');
  });

  it('restores body scroll when closed', () => {
    function Wrapper() {
      const [open, setOpen] = useState(true);
      return (
        <>
          <Modal open={open} onClose={() => setOpen(false)} title="Scroll Lock">
            <p>body</p>
          </Modal>
          <button onClick={() => setOpen(false)}>close</button>
        </>
      );
    }
    document.body.style.overflow = 'auto';
    const { unmount } = render(<Wrapper />);
    expect(document.body.style.overflow).toBe('hidden');
    fireEvent.click(screen.getByText('close'));
    expect(document.body.style.overflow).toBe('auto');
    unmount();
  });

  it('has role="dialog" and aria-modal="true"', () => {
    render(
      <Modal open onClose={() => {}} title="ARIA Test">
        <p>body</p>
      </Modal>,
    );
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAttribute('aria-label', 'ARIA Test');
  });

  it('traps Tab focus inside the dialog', () => {
    render(<ModalWithFocusableContent />);
    const firstInput = screen.getByTestId('first-input');
    const lastInput = screen.getByTestId('last-input');

    act(() => { firstInput.focus(); });
    expect(document.activeElement).toBe(firstInput);

    act(() => {
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab' });
    });
    expect(document.activeElement).toBe(screen.getByTestId('mid-btn'));

    act(() => {
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab' });
    });
    expect(document.activeElement).toBe(lastInput);

    act(() => {
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab' });
    });
    expect(document.activeElement).toBe(firstInput);
  });

  it('traps Shift+Tab focus inside the dialog', () => {
    render(<ModalWithFocusableContent />);
    const firstInput = screen.getByTestId('first-input');
    const lastInput = screen.getByTestId('last-input');

    act(() => { firstInput.focus(); });
    expect(document.activeElement).toBe(firstInput);

    act(() => {
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab', shiftKey: true });
    });
    expect(document.activeElement).toBe(lastInput);
  });

  it('restores focus to the previously focused element on close', () => {
    function Wrapper() {
      const [open, setOpen] = useState(false);
      return (
        <>
          <button data-testid="outside-btn" onClick={() => setOpen(true)}>open</button>
          <Modal open={open} onClose={() => setOpen(false)} title="Restore Focus">
            <p>body</p>
          </Modal>
        </>
      );
    }
    render(<Wrapper />);
    const trigger = screen.getByTestId('outside-btn');
    act(() => { trigger.focus(); });
    act(() => { fireEvent.click(trigger); });

    expect(screen.getByRole('dialog')).toBeInTheDocument();

    act(() => {
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
  });
});
