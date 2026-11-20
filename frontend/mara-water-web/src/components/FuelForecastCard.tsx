import React, { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { formatDate } from '../utils/dates';

interface ForecastRow {
  id: string;
  date: string;
  vehicle: string | null;
  liters: number;
  cost: number;
  odometer: number;
  distance_km: number;
  empty_at_km: number;
}

interface Forecast {
  from: string;
  km_per_litre: number;
  rows: ForecastRow[];
}

/** Distance left in the tank from the mileage written at each fill. */
export default function FuelForecastCard() {
  const [data, setData] = useState<Forecast | null>(null);
  const [rate, setRate] = useState('10');
  const [saving, setSaving] = useState(false);

  const load = () => {
    api.get('/fleet/fuel-forecast')
      .then((res) => {
        setData(res.data.data);
        setRate(String(res.data.data?.km_per_litre ?? 10));
      })
      .catch(() => {});
  };

  useEffect(() => { load(); }, []);

  const save = async () => {
    const km = parseFloat(rate);
    if (!km || km < 1) {
      toast.error('Enter how many kilometres one litre covers');
      return;
    }
    setSaving(true);
    try {
      const res = await api.put('/fleet/fuel-forecast', { km_per_litre: km });
      setData(res.data.data);
      toast.success('Fuel range updated');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Could not save the fuel figure');
    } finally {
      setSaving(false);
    }
  };

  if (!data) return null;

  return (
    <div className="mara-panel p-4">
      <div className="flex flex-wrap items-end justify-between gap-3 mb-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Fuel range</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            From {formatDate(data.from)}. Mileage at the fill, times {data.km_per_litre} km per litre, is how far the tank should go before it is empty.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-gray-500 dark:text-gray-400">
            km per litre
            <input
              type="number"
              min="1"
              max="40"
              step="0.1"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              className="mt-1 block w-24 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm"
            />
          </label>
          <button
            type="button"
            onClick={save}
            disabled={saving}
            className="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
      {data.rows.length === 0 ? (
        <p className="text-sm text-gray-500">No fill with a mileage reading since {formatDate(data.from)}.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                <th className="pr-4 py-1">Date</th>
                <th className="pr-4 py-1">Vehicle</th>
                <th className="pr-4 py-1">Litres</th>
                <th className="pr-4 py-1">Mileage</th>
                <th className="pr-4 py-1">Distance before empty</th>
                <th className="pr-4 py-1">Empty at</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
              {data.rows.map((row) => (
                <tr key={row.id}>
                  <td className="pr-4 py-1.5">{formatDate(row.date)}</td>
                  <td className="pr-4 py-1.5">{row.vehicle || '—'}</td>
                  <td className="pr-4 py-1.5">{row.liters} L</td>
                  <td className="pr-4 py-1.5">{row.odometer.toLocaleString()} km</td>
                  <td className="pr-4 py-1.5 font-medium">{row.distance_km.toLocaleString()} km</td>
                  <td className="pr-4 py-1.5">{row.empty_at_km.toLocaleString()} km</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
