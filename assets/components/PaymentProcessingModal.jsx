import React, { useState, useMemo } from 'react';
import { X, Receipt, AlertTriangle, CheckCircle2, History } from 'lucide-react';

/**
 * PaymentProcessingModal (Quick Payment Slide-Over Panel)
 *
 * Includes:
 * 1. 'Payer Name / Sponsor' text input in the top section of the payment form,
 *    styled identically to the 'OR Number' input field.
 * 2. payerName state initialized strictly to empty ('') — never auto-filled with the student's name.
 * 3. payerName included in the final payment submission payload.
 * 4. Manual-only fee selection and amount entry (no Auto-Checked toggle or balance pre-fill).
 */
export default function PaymentProcessingModal({
  isOpen = true,
  onClose,
  student = {
    id: 1,
    name: 'Dela Cruz, Juan M.',
    studentNumber: '2026-0001',
    yearLevel: 'College I',
    academicYear: '2026-2027',
    semester: '1st',
    remainingBalance: 15500.0,
    previousBalance: 2500.0,
  },
  initialFees = [
    { id: 'prev_bal', particular: 'Previous Balance Forwarded', remainingBalance: 2500.0, isPreviousBalance: true },
    { id: 'tuition', particular: 'Tuition Fee', remainingBalance: 8500.0, isPreviousBalance: false },
    { id: 'board', particular: 'Board & Lodging', remainingBalance: 3500.0, isPreviousBalance: false },
    { id: 'misc', particular: 'Miscellaneous & Library Fee', remainingBalance: 1000.0, isPreviousBalance: false },
  ],
  suggestedOrNumber = 'OR-2026-0104',
  onSubmitPayment,
}) {
  // Form metadata state (payerName defaults strictly to empty string '')
  const [orNumber, setOrNumber] = useState(suggestedOrNumber);
  const [payerName, setPayerName] = useState('');
  const [paymentDate, setPaymentDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [amountTendered, setAmountTendered] = useState('');

  // Manual Entry Default:
  // Every fee item starts UNCHECKED (checked: false) and its input starts empty ('')
  const [feeSelections, setFeeSelections] = useState(() =>
    initialFees.reduce((acc, fee) => {
      acc[fee.id] = {
        checked: false,
        amount: '',
      };
      return acc;
    }, {})
  );

  // Toggle individual fee checkbox without auto-filling the balance
  const handleCheckboxChange = (feeId, isChecked) => {
    setFeeSelections((prev) => ({
      ...prev,
      [feeId]: {
        ...prev[feeId],
        checked: isChecked,
        // Reset amount if unchecked; keep empty when checked so cashier types manually
        amount: isChecked ? (prev[feeId]?.amount ?? '') : '',
      },
    }));
  };

  // Update manually typed amount for a specific fee
  const handleFeeAmountChange = (feeId, rawValue) => {
    setFeeSelections((prev) => ({
      ...prev,
      [feeId]: {
        ...prev[feeId],
        amount: rawValue,
      },
    }));
  };

  // Total Calculation:
  // Strictly sums only the amounts manually entered by the cashier for checked fees
  const amountToPay = useMemo(() => {
    const sum = initialFees.reduce((total, fee) => {
      const entry = feeSelections[fee.id];
      if (!entry || !entry.checked) return total;
      const parsed = parseFloat(entry.amount);
      return total + (Number.isFinite(parsed) && parsed > 0 ? parsed : 0);
    }, 0);
    return Math.round(sum * 100) / 100;
  }, [initialFees, feeSelections]);

  // Cash tendered & change (Sukli) calculation
  const numericTendered = parseFloat(amountTendered) || 0;
  const effectivePayment =
    amountTendered.trim() === '' || numericTendered === 0
      ? amountToPay
      : Math.min(numericTendered, amountToPay);
  const changeAmount =
    numericTendered > amountToPay
      ? Math.round((numericTendered - amountToPay) * 100) / 100
      : 0;
  const isOverpaying =
    effectivePayment > (student.remainingBalance || 0) + 0.005 &&
    (student.remainingBalance || 0) > 0;
  const newRemainingBalance = Math.max(
    0,
    Math.round(((student.remainingBalance || 0) - effectivePayment) * 100) / 100
  );

  const handleDenominationClick = (mode, val = 0) => {
    if (mode === 'exact') {
      setAmountTendered(amountToPay > 0 ? amountToPay.toFixed(2) : '');
    } else if (mode === 'add') {
      const base = numericTendered > 0 ? numericTendered : amountToPay;
      setAmountTendered((base + val).toFixed(2));
    } else if (mode === 'set') {
      setAmountTendered(val.toFixed(2));
    }
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (amountToPay <= 0 || isOverpaying) return;

    const selectedParticulars = initialFees
      .filter((fee) => feeSelections[fee.id]?.checked && parseFloat(feeSelections[fee.id]?.amount) > 0)
      .map((fee) => ({
        id: fee.id,
        particular: fee.particular,
        amount: parseFloat(feeSelections[fee.id].amount),
      }));

    const payload = {
      seminarianId: student.id,
      orNumber,
      payerName: payerName.trim(),
      payer_name: payerName.trim(),
      paymentDate,
      paymentMethod,
      amountToPay,
      amountTendered: numericTendered > 0 ? numericTendered : amountToPay,
      changeAmount,
      particulars: selectedParticulars,
    };

    if (onSubmitPayment) {
      onSubmitPayment(payload);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/40 backdrop-blur-[1px]">
      <div className="w-full max-w-md bg-white h-full shadow-2xl flex flex-col justify-between overflow-y-auto">
        {/* Header */}
        <div>
          <div className="bg-emerald-900 text-white px-5 py-4 flex items-center justify-between">
            <div>
              <h2 className="text-base font-extrabold flex items-center gap-2">
                <Receipt className="w-4 h-4 text-amber-300" />
                Quick Payment
              </h2>
              <p className="text-xs text-emerald-100 mt-0.5">
                {student.name} ({student.studentNumber} • {student.yearLevel})
              </p>
            </div>
            <button
              type="button"
              onClick={onClose}
              className="text-white/80 hover:text-white p-1 rounded-lg transition-colors"
              aria-label="Close panel"
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Remaining Balance Summary Strip */}
          <div className="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <div>
              <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 block">
                Remaining Balance Due
              </span>
              <span className="text-xl font-black text-red-600">
                ₱{(student.remainingBalance || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </span>
            </div>
            <span className="text-xs font-bold bg-slate-200 text-slate-800 px-2.5 py-1 rounded-full">
              {student.academicYear} • {student.semester} Sem
            </span>
          </div>

          {/* Payment Form */}
          <form id="quickPaymentForm" onSubmit={handleSubmit} className="p-5 space-y-4">
            {/* Top Section: OR Number, Date, Payment Method, and Payer Name / Sponsor */}
            <div>
              <label className="text-xs font-bold text-slate-600 block mb-1">
                Official Receipt (OR) Number <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                name="or_number"
                required
                value={orNumber}
                onChange={(e) => setOrNumber(e.target.value)}
                placeholder="e.g. OR-2026-0001"
                className="w-full px-3 py-2 text-sm font-bold border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-600"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">
                  Date <span className="text-red-500">*</span>
                </label>
                <input
                  type="date"
                  name="payment_date"
                  required
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full px-2.5 py-1.5 text-sm border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-600"
                />
              </div>
              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">
                  Payment Method <span className="text-red-500">*</span>
                </label>
                <select
                  name="method"
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                  className="w-full px-2.5 py-1.5 text-sm border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-600"
                >
                  <option value="cash">Cash</option>
                  <option value="bank_deposit">Bank Deposit</option>
                  <option value="online_transfer">Online Transfer</option>
                  <option value="check">Check</option>
                </select>
              </div>
            </div>

            {/* Payer Name / Sponsor Field (Strictly empty default, same Tailwind classes as OR Number) */}
            <div>
              <label className="text-xs font-bold text-slate-600 block mb-1">
                Payer Name / Sponsor
              </label>
              <input
                type="text"
                name="payer_name"
                value={payerName}
                onChange={(e) => setPayerName(e.target.value)}
                placeholder="Enter payer or sponsor name"
                className="w-full px-3 py-2 text-sm font-bold border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-600"
              />
            </div>

            {/* FEES BEING PAID Section (Manual entry required) */}
            <div>
              <div className="flex items-baseline justify-between mb-1.5">
                <label className="text-xs font-bold uppercase tracking-wider text-slate-600">
                  Fees Being Paid
                </label>
              </div>

              <div className="flex flex-col gap-2 max-h-48 overflow-y-auto border border-slate-200 rounded-md p-2 bg-slate-50/70">
                {initialFees.map((fee) => {
                  const remBal = Number(fee.remainingBalance || 0);
                  const isZero = remBal <= 0.0001;
                  const isChecked = Boolean(feeSelections[fee.id]?.checked);
                  const currentVal = feeSelections[fee.id]?.amount ?? '';

                  return (
                    <div
                      key={fee.id}
                      className={`flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-md text-xs border transition-colors ${
                        fee.isPreviousBalance && !isZero
                          ? 'bg-rose-50 border-red-400'
                          : isChecked
                          ? 'bg-white border-emerald-600'
                          : 'bg-white border-slate-300'
                      }`}
                    >
                      <label className="flex items-center gap-2 cursor-pointer flex-1 font-semibold text-slate-800">
                        <input
                          type="checkbox"
                          checked={isChecked}
                          onChange={(e) => handleCheckboxChange(fee.id, e.target.checked)}
                          className="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600 cursor-pointer"
                        />
                        <span className="flex items-center gap-1.5 flex-wrap">
                          {fee.isPreviousBalance && (
                            <History className="w-3.5 h-3.5 text-red-500 shrink-0" />
                          )}
                          <span className={fee.isPreviousBalance ? 'text-red-900 font-extrabold' : ''}>
                            {fee.particular}
                          </span>
                          <span
                            className={`text-[10px] px-1.5 py-0.5 rounded font-semibold ${
                              isZero
                                ? 'bg-slate-200 text-slate-600'
                                : fee.isPreviousBalance
                                ? 'bg-red-100 text-red-800'
                                : 'bg-amber-100 text-amber-800'
                            }`}
                          >
                            {isZero
                              ? 'Paid (₱0.00)'
                              : `Bal: ₱${remBal.toLocaleString('en-PH', {
                                  minimumFractionDigits: 2,
                                  maximumFractionDigits: 2,
                                })}`}
                          </span>
                        </span>
                      </label>

                      <div className="flex items-center w-28 shrink-0">
                        <span className="text-xs text-slate-500 mr-1">₱</span>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
                          disabled={!isChecked || isZero}
                          value={currentVal}
                          onChange={(e) => handleFeeAmountChange(fee.id, e.target.value)}
                          className={`w-full px-2 py-1 text-right text-xs font-bold rounded border ${
                            !isChecked || isZero
                              ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed'
                              : 'bg-white text-slate-900 border-slate-300 focus:outline-none focus:ring-1 focus:ring-emerald-600'
                          }`}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Totals, Amount to Pay & Cash Tendered */}
            <div className="bg-slate-900 text-white p-4 rounded-lg space-y-3">
              <div>
                <div className="flex items-baseline justify-between mb-1">
                  <label className="text-xs uppercase font-bold text-slate-300">
                    Amount to Pay (₱):
                  </label>
                  <span className="text-xs font-bold text-amber-200">
                    Fees: ₱{amountToPay.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  readOnly
                  value={amountToPay.toFixed(2)}
                  className="w-full px-3 py-2 text-lg font-black rounded-md border-2 border-amber-200 bg-white text-emerald-950 focus:outline-none"
                />
              </div>

              <div>
                <div className="flex items-baseline justify-between mb-1">
                  <label className="text-xs uppercase font-bold text-slate-300">
                    Amount Tendered (Cash):
                  </label>
                  <span className="text-[11px] text-emerald-300">Exact by default</span>
                </div>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  value={amountTendered}
                  onChange={(e) => setAmountTendered(e.target.value)}
                  className="w-full px-3 py-2 text-lg font-black rounded-md border-2 border-sky-400 bg-white text-slate-900 focus:outline-none"
                />
              </div>

              {/* Quick Denomination Chips */}
              <div className="flex flex-wrap gap-1.5">
                <button
                  type="button"
                  onClick={() => handleDenominationClick('exact')}
                  className="px-2 py-0.5 text-xs font-bold bg-white/20 hover:bg-white/30 text-white border border-white/30 rounded"
                >
                  Exact
                </button>
                <button
                  type="button"
                  onClick={() => handleDenominationClick('add', 500)}
                  className="px-2 py-0.5 text-xs bg-white/15 hover:bg-white/25 text-white border border-white/20 rounded"
                >
                  +500
                </button>
                <button
                  type="button"
                  onClick={() => handleDenominationClick('add', 1000)}
                  className="px-2 py-0.5 text-xs bg-white/15 hover:bg-white/25 text-white border border-white/20 rounded"
                >
                  +1k
                </button>
                <button
                  type="button"
                  onClick={() => handleDenominationClick('set', 1000)}
                  className="px-2 py-0.5 text-xs bg-white/15 hover:bg-white/25 text-white border border-white/20 rounded"
                >
                  ₱1k
                </button>
                <button
                  type="button"
                  onClick={() => handleDenominationClick('set', 5000)}
                  className="px-2 py-0.5 text-xs bg-white/15 hover:bg-white/25 text-white border border-white/20 rounded"
                >
                  ₱5k
                </button>
                <button
                  type="button"
                  onClick={() => handleDenominationClick('set', 10000)}
                  className="px-2 py-0.5 text-xs bg-white/15 hover:bg-white/25 text-white border border-white/20 rounded"
                >
                  ₱10k
                </button>
              </div>

              {/* Change (Sukli) & Remaining Balance */}
              <div className="flex items-center justify-between border-t border-white/15 pt-2.5">
                <div>
                  <span className="text-xs uppercase font-bold text-emerald-300 block">
                    Change (Sukli):
                  </span>
                  <span className="text-[11px] text-emerald-200">
                    New Balance: ₱{newRemainingBalance.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>
                <input
                  type="text"
                  readOnly
                  value={`₱${changeAmount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                  className="bg-emerald-950 text-emerald-300 text-xl font-black px-2.5 py-1 rounded-md border-2 border-emerald-500 w-32 text-right cursor-not-allowed"
                />
              </div>
            </div>

            {/* Overpayment Warning */}
            {isOverpaying && (
              <div className="p-2.5 rounded-md bg-red-100 border border-red-400 text-red-800 text-xs font-semibold flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 shrink-0" />
                <span>
                  Payment amount (₱{effectivePayment.toLocaleString('en-PH', { minimumFractionDigits: 2 })}) exceeds remaining balance of ₱{student.remainingBalance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}.
                </span>
              </div>
            )}

            {/* Submit Button */}
            <button
              type="submit"
              disabled={amountToPay <= 0 || isOverpaying}
              className="w-full py-2.5 px-4 bg-emerald-800 hover:bg-emerald-700 disabled:bg-slate-300 disabled:text-slate-500 disabled:cursor-not-allowed text-white font-extrabold rounded-md text-sm flex items-center justify-center gap-2 transition-colors"
            >
              <CheckCircle2 className="w-4 h-4" />
              Post Payment &amp; Print OR
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
