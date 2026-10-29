import React, { useState } from 'react';
import { Lock } from 'lucide-react';
import { toast } from 'react-hot-toast';

const OPS_PIN_KEY = 'mara_ops_pin_ok';
const EXPECTED_PIN = '0324';

function isUnlocked(): boolean {
  try {
    return sessionStorage.getItem(OPS_PIN_KEY) === '1';
  } catch {
    return false;
  }
}

interface OpsPinGateProps {
  children: React.ReactNode;
}

const OpsPinGate: React.FC<OpsPinGateProps> = ({ children }) => {
  const [unlocked, setUnlocked] = useState(isUnlocked);
  const [pin, setPin] = useState('');
  const [error, setError] = useState('');

  const tryUnlock = () => {
    if (pin === EXPECTED_PIN) {
      try {
        sessionStorage.setItem(OPS_PIN_KEY, '1');
      } catch {
        // ignore storage failures — still unlock this session in memory
      }
      setUnlocked(true);
      setError('');
      setPin('');
      return;
    }
    setError('Wrong PIN');
    toast.error('Wrong PIN');
  };

  if (unlocked) {
    return <>{children}</>;
  }

  return (
    <div className="min-h-[70vh] flex items-center justify-center px-4">
      <div className="w-full max-w-sm mara-panel p-8">
        <div className="flex flex-col items-center text-center mb-6">
          <div className="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-3">
            <Lock className="w-6 h-6 text-gray-600 dark:text-gray-300" />
          </div>
          <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-100">Operations locked</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter the operations PIN to continue.</p>
        </div>
        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">PIN</label>
        <input
          type="password"
          inputMode="numeric"
          autoComplete="off"
          value={pin}
          onChange={(e) => {
            setPin(e.target.value);
            if (error) setError('');
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') tryUnlock();
          }}
          className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="••••"
        />
        {error && (
          <p className="mt-2 text-sm text-red-600">{error}</p>
        )}
        <button
          type="button"
          onClick={tryUnlock}
          className="mt-4 w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium"
        >
          Unlock
        </button>
      </div>
    </div>
  );
};

export default OpsPinGate;
