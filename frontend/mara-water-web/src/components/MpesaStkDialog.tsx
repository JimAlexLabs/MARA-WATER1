import React, { useEffect, useRef, useState } from 'react';
import { api } from '../services/api';

export type StkPayment = {
  id: string;
  status: string;
  amount: number;
  phone: string;
  reference: string;
  result_desc?: string | null;
  mpesa_receipt?: string | null;
  paybill?: string | null;
  mode?: string;
  order_id?: string | null;
  driver_trip_sale_id?: string | null;
};

type Props = {
  payment: StkPayment;
  onClose: () => void;
  onFinished: (payment: StkPayment) => void;
};

const MpesaStkDialog: React.FC<Props> = ({ payment, onClose, onFinished }) => {
  const [current, setCurrent] = useState(payment);
  const [seconds, setSeconds] = useState(90);
  const [busy, setBusy] = useState(false);
  const finishedRef = useRef(onFinished);
  finishedRef.current = onFinished;

  useEffect(() => {
    if (current.status !== 'pending') return;
    const tick = window.setInterval(() => setSeconds((s) => Math.max(0, s - 1)), 1000);
    const poll = window.setInterval(async () => {
      try {
        const res = await api.get(`/payments/${current.id}/status`);
        const next = res.data.data as StkPayment;
        setCurrent(next);
        if (next.status !== 'pending') finishedRef.current(next);
      } catch {
        /* keep polling */
      }
    }, 3000);
    return () => {
      window.clearInterval(tick);
      window.clearInterval(poll);
    };
  }, [current.id, current.status]);

  const recheck = async () => {
    setBusy(true);
    try {
      const res = await api.post(`/payments/${current.id}/recheck`);
      const next = res.data.data as StkPayment;
      setCurrent(next);
      if (next.status !== 'pending') onFinished(next);
    } finally {
      setBusy(false);
    }
  };

  const retry = async () => {
    setBusy(true);
    try {
      const res = await api.post('/payments/stk', {
        phone: current.phone,
        order_id: current.order_id,
        driver_trip_sale_id: current.driver_trip_sale_id,
      });
      setCurrent(res.data.data);
      setSeconds(90);
    } finally {
      setBusy(false);
    }
  };

  const payCash = async () => {
    setBusy(true);
    try {
      const res = await api.post(`/payments/${current.id}/cash`);
      const next = res.data.data as StkPayment;
      setCurrent(next);
      onFinished(next);
    } finally {
      setBusy(false);
    }
  };

  const waiting = current.status === 'pending';

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[70] p-4">
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-3">
        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
          {waiting ? 'Waiting for the M-Pesa PIN' : current.status === 'success' ? 'Payment confirmed' : 'Payment not completed'}
        </h3>
        {current.mode === 'mock' && (
          <p className="text-xs text-amber-700 dark:text-amber-300">
            Test mode: no prompt is sent to the phone. A test result arrives in a few seconds. Numbers ending 0000 cancel, 1111 time out, 2222 fail for low balance.
          </p>
        )}
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {waiting
            ? `Ask the customer to enter their M-Pesa PIN on ${current.phone}. ${seconds}s left.`
            : (current.result_desc || current.status)}
        </p>
        <dl className="text-sm space-y-1 bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
          <div className="flex justify-between"><dt className="text-gray-500">Amount</dt><dd>KES {Number(current.amount).toLocaleString()}</dd></div>
          <div className="flex justify-between"><dt className="text-gray-500">Account</dt><dd>{current.reference}</dd></div>
          {current.paybill && <div className="flex justify-between"><dt className="text-gray-500">Paybill</dt><dd>{current.paybill}</dd></div>}
          {current.mpesa_receipt && <div className="flex justify-between"><dt className="text-gray-500">Receipt</dt><dd className="font-medium">{current.mpesa_receipt}</dd></div>}
        </dl>
        {!waiting && current.status !== 'success' && (
          <p className="text-xs text-gray-500">Or pay on the M-Pesa menu: Paybill {current.paybill || '—'}, account {current.reference}.</p>
        )}
        <div className="flex flex-wrap justify-end gap-2">
          {waiting && (
            <button type="button" onClick={recheck} disabled={busy} className="px-3 py-2 text-sm border rounded-md">Re-check</button>
          )}
          {!waiting && current.status !== 'success' && (
            <>
              <button type="button" onClick={retry} disabled={busy} className="px-3 py-2 text-sm bg-green-700 text-white rounded-md">Retry</button>
              <button type="button" onClick={payCash} disabled={busy} className="px-3 py-2 text-sm border rounded-md">Pay by cash</button>
            </>
          )}
          <button type="button" onClick={onClose} className="px-3 py-2 text-sm bg-blue-600 text-white rounded-md">
            {current.status === 'success' ? 'Done' : 'Close'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default MpesaStkDialog;
