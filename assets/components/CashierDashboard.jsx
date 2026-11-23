import React, { useState } from "react";

/**
 * CashierDashboard.jsx
 *
 * Main Content Flex Layout (`flex flex-col xl:flex-row gap-6 items-start`):
 * - Left Column (`flex-1 w-full flex flex-col gap-6 overflow-hidden`):
 *   1. Top Header ("Cashier Dashboard" title, greeting, search bar, and term selectors) at the very top
 *   2. 4 Stat Cards in a single horizontal row (`grid grid-cols-1 lg:grid-cols-4 gap-4`)
 *   3. "Action Required" and "Recent Transactions" sections below the stat cards
 * - Right Column (`w-full xl:w-80 shrink-0`):
 *   - Calendar component placed at the very top right, horizontally flush with the Top Header.
 */
export default function CashierDashboard({
  userName = "Cashier",
  activeTerm = { academicYear: "2026-2027", semester: "1st" },
  stats = {
    collectionsToday: 12500.0,
    thisMonth: 184250.0,
    outstandingDue: 45800.0,
    pendingSoa: 2,
  },
  actionRequiredRequests = [],
  recentTransactions = [],
  CalendarComponent,
}) {
  const [globalSearch, setGlobalSearch] = useState("");
  const [tableSearch, setTableSearch] = useState("");

  const filteredTransactions = recentTransactions.filter((tx) => {
    const q = (tableSearch || globalSearch).toLowerCase().trim();
    if (!q) return true;
    return (
      (tx.orNumber || "").toLowerCase().includes(q) ||
      (tx.studentName || "").toLowerCase().includes(q)
    );
  });

  const statCards = [
    {
      key: "collectionsToday",
      label: "Collections Today",
      value: `₱${Number(stats.collectionsToday || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })}`,
      subtext: "Daily Real-Time Total",
      iconClass: "fa-solid fa-calendar-day",
    },
    {
      key: "thisMonth",
      label: "This Month",
      value: `₱${Number(stats.thisMonth || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })}`,
      subtext: "Month-to-Date Inflow",
      iconClass: "fa-solid fa-chart-line",
    },
    {
      key: "outstandingDue",
      label: "Outstanding Due",
      value: `₱${Number(stats.outstandingDue || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })}`,
      subtext: "Active Unpaid Invoices",
      iconClass: "fa-solid fa-scale-unbalanced",
    },
    {
      key: "pendingSoa",
      label: "Pending SOA",
      value: stats.pendingSoa ?? 0,
      subtext: (stats.pendingSoa ?? 0) > 0 ? "Requires Clearance" : "All Clear",
      iconClass: "fa-solid fa-file-invoice",
    },
  ];

  return (
    <div className="flex flex-col xl:flex-row gap-6 items-start font-sans">
      {/* LEFT COLUMN: Top Header + 4 Horizontal Stat Cards + Action Required + Recent Transactions */}
      <div className="flex-1 w-full flex flex-col gap-6 overflow-hidden">
        {/* 1. Top Header ("Cashier Dashboard" Title & Search Bar) */}
        <header className="flex items-center justify-between gap-3 sm:gap-4 bg-white text-[#59443F] px-4 py-3 sm:px-6 sm:py-3.5 rounded-3xl lg:rounded-[2rem] border border-[#E8C7C5]/50 shadow-sm">
          <div className="min-w-0">
            <h1 className="text-base sm:text-lg font-extrabold text-[#59443F] leading-tight tracking-tight truncate">
              Cashier Dashboard
            </h1>
            <p className="text-xs font-medium text-[#8B7168] mt-0.5 truncate">
              Welcome, {userName}
            </p>
          </div>

          <div className="flex-1 max-w-xs sm:max-w-md mx-2 sm:mx-4 flex items-center justify-center">
            <div className="relative w-full">
              <i className="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-[#8B7168]" />
              <input
                type="text"
                value={globalSearch}
                onChange={(e) => setGlobalSearch(e.target.value)}
                placeholder="Search records, students, courses..."
                className="w-full pl-10 pr-9 py-2 rounded-full bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] focus:bg-white transition-all"
              />
            </div>
          </div>

          <div className="flex items-center gap-2.5 shrink-0">
            <span className="px-3.5 py-2 rounded-full bg-[#F8EEEE] text-xs font-bold text-[#59443F] border border-[#E8C7C5]/70">
              A.Y. {activeTerm.academicYear}
            </span>
            <span className="px-3.5 py-2 rounded-full bg-[#F8EEEE] text-xs font-bold text-[#59443F] border border-[#E8C7C5]/70">
              {activeTerm.semester} Semester
            </span>
          </div>
        </header>

        {/* 2. 4 Stat Cards in a Single Horizontal Row */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
          {statCards.map((card) => (
            <div
              key={card.key}
              className="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 shadow-md hover:shadow-lg transition-all duration-200 flex flex-col justify-between"
            >
              <div className="flex items-center justify-between gap-2 mb-2">
                <span className="text-[11px] font-semibold text-[#8B7168] tracking-wider uppercase truncate">
                  {card.label}
                </span>
                <div className="w-9 h-9 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shrink-0">
                  <i className={card.iconClass} />
                </div>
              </div>
              <div>
                <div className="text-xl sm:text-2xl font-black text-[#59443F] leading-tight truncate">
                  {card.value}
                </div>
                <span className="text-[11px] font-medium text-[#8B7168] mt-1 block truncate">
                  {card.subtext}
                </span>
              </div>
            </div>
          ))}
        </div>

        {/* 3. Action Required Section */}
        <div className="bg-white rounded-3xl border border-amber-200/80 shadow-md overflow-hidden">
          <div className="p-5 sm:p-6 pb-4 border-b border-amber-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-amber-50/40">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-2xl bg-amber-100/80 text-amber-800 border border-amber-200 flex items-center justify-center shrink-0">
                <i className="fa-solid fa-bell text-base" />
              </div>
              <div>
                <h2 className="font-extrabold text-sm sm:text-base text-amber-950 tracking-tight">
                  Action Required: {actionRequiredRequests.length} Student Statement
                  Request{actionRequiredRequests.length === 1 ? "" : "s"}
                </h2>
                <p className="text-xs text-amber-700/90 font-medium">
                  Clear statements for official clearances
                </p>
              </div>
            </div>
            <a
              href="/cashier/requests"
              className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-white hover:bg-amber-100 text-amber-900 text-xs font-bold border border-amber-200 transition self-start sm:self-center"
            >
              View All Requests <i className="fa-solid fa-arrow-right text-[10px]" />
            </a>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-amber-50/30 border-b border-amber-100 text-[11px] font-extrabold text-amber-800 uppercase tracking-wider">
                  <th className="py-3 px-6">Seminarian</th>
                  <th className="py-3 px-4">Academic Term</th>
                  <th className="py-3 px-4">Requested</th>
                  <th className="py-3 px-6 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-amber-100/60">
                {actionRequiredRequests.length === 0 ? (
                  <tr>
                    <td
                      colSpan={4}
                      className="text-center py-8 px-4 text-[#8B7168] font-medium text-xs"
                    >
                      No pending statement requests requiring action.
                    </td>
                  </tr>
                ) : (
                  actionRequiredRequests.map((req) => (
                    <tr key={req.id} className="hover:bg-amber-50/20 transition">
                      <td className="py-3.5 px-6 font-bold text-[#59443F]">
                        {req.studentName}{" "}
                        <span className="text-[11px] font-normal text-[#8B7168]">
                          (#{req.seminarianId})
                        </span>
                      </td>
                      <td className="py-3.5 px-4">
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                          {req.academicYear} &middot; {req.semester}
                        </span>
                      </td>
                      <td className="py-3.5 px-4 text-[#8B7168] text-[11px]">
                        {req.requestedAt}
                      </td>
                      <td className="py-3.5 px-6 text-right">
                        <a
                          href={`/cashier/soa?student_id=${req.seminarianId}`}
                          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-bold transition"
                        >
                          <i className="fa-solid fa-file-invoice text-[10px]" /> Issue
                          SOA
                        </a>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* 4. Recent Transactions Section */}
        <div className="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-md overflow-hidden">
          <div className="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0">
                <i className="fa-solid fa-clock-rotate-left text-base" />
              </div>
              <div>
                <h2 className="font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">
                  Recent Transactions
                </h2>
                <p className="text-xs text-[#8B7168] font-medium">
                  Today and recent payment receipts
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
              <input
                type="text"
                value={tableSearch}
                onChange={(e) => setTableSearch(e.target.value)}
                placeholder="Filter payments..."
                className="w-full sm:w-48 px-3.5 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]"
              />
              <a
                href="/cashier/payments"
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition whitespace-nowrap"
              >
                <i className="fa-solid fa-plus text-xs" /> Record Payment
              </a>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                  <th className="py-3 px-6">Receipt / OR</th>
                  <th className="py-3 px-4">Seminarian</th>
                  <th className="py-3 px-4 text-right">Amount</th>
                  <th className="py-3 px-4">Date</th>
                  <th className="py-3 px-6 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E8C7C5]/20">
                {filteredTransactions.length === 0 ? (
                  <tr>
                    <td
                      colSpan={5}
                      className="text-center py-10 px-4 text-[#8B7168] font-medium text-xs"
                    >
                      No recent payments recorded.
                    </td>
                  </tr>
                ) : (
                  filteredTransactions.map((tx) => (
                    <tr key={tx.id} className="hover:bg-[#F8EEEE]/40 transition">
                      <td className="py-3.5 px-6 font-mono font-bold text-[#59443F]">
                        {tx.orNumber || `OR#${tx.id}`}
                      </td>
                      <td className="py-3.5 px-4 font-bold text-[#59443F]">
                        {tx.studentName}
                      </td>
                      <td className="py-3.5 px-4 text-right font-black text-[#59443F]">
                        ₱
                        {Number(tx.amount || 0).toLocaleString("en-PH", {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })}
                      </td>
                      <td className="py-3.5 px-4 text-[#8B7168] text-[11px]">
                        {tx.paymentDate}
                      </td>
                      <td className="py-3.5 px-6 text-right">
                        <a
                          href={`/cashier/receipt?payment_id=${tx.id}`}
                          className="inline-flex items-center gap-1 px-3 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[11px] font-bold border border-[#E8C7C5]/60 transition"
                        >
                          <i className="fa-solid fa-print text-[10px]" /> Receipt
                        </a>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* RIGHT COLUMN (w-full xl:w-80 shrink-0): Calendar Placed at Very Top Right Flush with Top Header */}
      <div className="w-full xl:w-80 shrink-0 flex flex-col gap-6">
        <div className="bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 shadow-md">
          {CalendarComponent ? (
            <CalendarComponent />
          ) : (
            <div id="timetableMiniCalendar" />
          )}
        </div>
      </div>
    </div>
  );
}
