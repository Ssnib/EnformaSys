import React, { useState } from "react";

/**
 * AcademicFormatorDashboard.jsx
 *
 * 2-Column Flex Layout (`flex flex-col xl:flex-row gap-6 items-start`):
 * - Left Column (`flex-1 flex flex-col gap-6 min-w-0`):
 *   1. Top Header ('Academic Formation Management' title, search bar, and term filters)
 *   2. 4 Stat Cards in a horizontal row (`grid grid-cols-1 lg:grid-cols-4 gap-4`)
 *   3. 'Recent Grade Updates' section directly below the stat cards
 * - Right Column (`w-full xl:w-[320px] shrink-0`):
 *   - Calendar component at the very top right, horizontally aligned with the Top Header.
 */
export default function AcademicFormatorDashboard({
  userGreeting = "Academic Formator",
  stats = {
    seminarians: 48,
    regular: 42,
    irregular: 6,
    monitoring: 3,
  },
  recentGradeUpdates = [],
  CalendarComponent,
}) {
  const [globalSearch, setGlobalSearch] = useState("");
  const [academicYear, setAcademicYear] = useState("2026-2027");
  const [semester, setSemester] = useState("1st");
  const [searchQuery, setSearchQuery] = useState("");

  const filteredUpdates = recentGradeUpdates.filter((item) => {
    const q = (searchQuery || globalSearch).toLowerCase().trim();
    if (!q) return true;
    return (
      (item.seminarianName || "").toLowerCase().includes(q) ||
      (item.subjectCode || "").toLowerCase().includes(q) ||
      (item.subjectName || "").toLowerCase().includes(q)
    );
  });

  const statCards = [
    {
      key: "seminarians",
      label: "Seminarians",
      value: stats.seminarians,
      subtext: "Total Enrolled",
      iconClass: "fa-solid fa-users",
    },
    {
      key: "regular",
      label: "Regular",
      value: stats.regular,
      subtext: "Good Standing",
      iconClass: "fa-solid fa-user-check",
    },
    {
      key: "irregular",
      label: "Irregular",
      value: stats.irregular,
      subtext: stats.irregular > 0 ? "Deficient / Backload" : "All Regular",
      iconClass: "fa-solid fa-triangle-exclamation",
    },
    {
      key: "monitoring",
      label: "Monitoring",
      value: stats.monitoring,
      subtext: "Observation",
      iconClass: "fa-solid fa-eye",
    },
  ];

  return (
    <div className="flex flex-col xl:flex-row gap-6 items-start font-sans">
      {/* LEFT COLUMN (flex-1 flex flex-col gap-6 min-w-0): Top Header, 4 Horizontal Stat Cards, and Recent Grade Updates */}
      <div className="flex-1 w-full flex flex-col gap-6 min-w-0">
        {/* 1. Top Header ('Academic Formation Management' title, search bar, and term filters) */}
        <header className="flex items-center justify-between gap-3 sm:gap-4 bg-white text-[#59443F] px-4 py-3 sm:px-6 sm:py-3.5 rounded-3xl lg:rounded-[2rem] border border-[#E8C7C5]/50 shadow-sm">
          <div className="min-w-0">
            <h1 className="text-base sm:text-lg font-extrabold text-[#59443F] leading-tight tracking-tight truncate">
              Academic Formation Management
            </h1>
            <p className="text-xs font-medium text-[#8B7168] mt-0.5 truncate">
              Welcome, {userGreeting}
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
                className="w-full pl-10 pr-9 py-2 rounded-full bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] focus:bg-white transition-all shadow-inner"
              />
            </div>
          </div>

          <div className="flex items-center gap-2.5 sm:gap-3 shrink-0">
            <select
              value={academicYear}
              onChange={(e) => setAcademicYear(e.target.value)}
              className="bg-[#F8EEEE] hover:bg-white text-xs font-bold text-[#59443F] px-3.5 py-2 rounded-full border border-[#E8C7C5]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] cursor-pointer transition"
            >
              <option value="2026-2027">A.Y. 2026-2027</option>
              <option value="2025-2026">A.Y. 2025-2026</option>
              <option value="2024-2025">A.Y. 2024-2025</option>
            </select>

            <select
              value={semester}
              onChange={(e) => setSemester(e.target.value)}
              className="bg-[#F8EEEE] hover:bg-white text-xs font-bold text-[#59443F] px-3.5 py-2 rounded-full border border-[#E8C7C5]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] cursor-pointer transition"
            >
              <option value="1st">1st Sem</option>
              <option value="2nd">2nd Sem</option>
              <option value="Summer">Summer</option>
            </select>
          </div>
        </header>

        {/* 2. 4 Stat Cards in a Single Horizontal Row (grid grid-cols-1 lg:grid-cols-4 gap-4) */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
          {statCards.map((card) => (
            <div
              key={card.key}
              className="bg-white rounded-2xl border border-[#E8C7C5]/50 p-4 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-between gap-2.5"
            >
              <div className="min-w-0">
                <span className="block text-[10px] font-bold text-[#8B7168] tracking-wider uppercase truncate">
                  {card.label}
                </span>
                <div className="text-xl font-black text-[#59443F] leading-none mt-1">
                  {card.value}
                </div>
                <span className="text-[10px] font-medium text-[#8B7168] mt-1 block truncate">
                  {card.subtext}
                </span>
              </div>
              <div className="w-8 h-8 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center text-xs shadow-2xs shrink-0">
                <i className={card.iconClass} />
              </div>
            </div>
          ))}
        </div>

        {/* 3. Recent Grade Updates Table Directly Below Stat Cards */}
        <div className="w-full bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-md overflow-hidden">
          <div className="p-5 sm:p-6 pb-4 border-b border-[#E8C7C5]/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0">
                <i className="fa-solid fa-clock-rotate-left text-base" />
              </div>
              <div>
                <h2 className="font-extrabold text-sm sm:text-base text-[#59443F] tracking-tight">
                  Recent Grade Updates
                </h2>
                <p className="text-xs text-[#8B7168] font-medium">
                  Latest evaluated and posted academic scores
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Filter grades..."
                className="w-full sm:w-56 px-3.5 py-1.5 rounded-xl bg-[#F8EEEE] text-xs font-semibold text-[#59443F] border border-[#E8C7C5]/60 placeholder-[#8B7168]/70 focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]"
              />
              <a
                href="/afms/grades"
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition whitespace-nowrap"
              >
                Grade Manager
              </a>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-[#F8EEEE]/70 border-b border-[#E8C7C5]/40 text-[11px] font-extrabold text-[#8B7168] uppercase tracking-wider">
                  <th className="py-3 px-6">Seminarian</th>
                  <th className="py-3 px-4">Subject</th>
                  <th className="py-3 px-4 text-center">Remarks</th>
                  <th className="py-3 px-6 text-right">Updated</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E8C7C5]/20 text-sm">
                {filteredUpdates.length === 0 ? (
                  <tr>
                    <td
                      colSpan={4}
                      className="text-center py-10 px-4 text-[#8B7168] font-medium text-xs"
                    >
                      No grade updates recorded yet for this term.
                    </td>
                  </tr>
                ) : (
                  filteredUpdates.map((row) => (
                    <tr
                      key={row.id}
                      className="hover:bg-[#F8EEEE]/40 transition-colors"
                    >
                      <td className="py-3.5 px-6 font-bold text-[#59443F]">
                        {row.seminarianName}
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="font-bold text-[#59443F] text-xs">
                          {row.subjectCode}
                        </div>
                        <div className="text-[11px] text-[#8B7168] font-medium">
                          {row.subjectName}
                        </div>
                      </td>
                      <td className="py-3.5 px-4 text-center">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                          {row.remarks || "Complete"}
                        </span>
                      </td>
                      <td className="py-3.5 px-6 text-right text-xs font-semibold text-[#8B7168]">
                        {row.updatedAt}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* RIGHT COLUMN (w-full xl:w-[320px] shrink-0): Calendar at Top Right Flush with the Top Header */}
      <div className="w-full xl:w-[320px] shrink-0 self-start bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 shadow-md">
        {CalendarComponent ? (
          <CalendarComponent />
        ) : (
          <div id="academicCalendarWidget" />
        )}
      </div>
    </div>
  );
}
