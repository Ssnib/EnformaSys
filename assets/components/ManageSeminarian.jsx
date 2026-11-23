import React, { useState, useEffect } from "react";

/**
 * ManageSeminarian.jsx
 * Refactored:
 * - Removed the "Add Seminarian" button from the top header.
 * - Removed `isAddModalOpen`, `setAddModalOpen`, `newSeminarianForm`, `handleAddSeminarianSubmit`,
 *   and the `<AddSeminarianModal />` (all relocated to `Enrollment.jsx`).
 * - Retains search, Academic Year / Year Level filters, and bottom table pagination.
 */
export default function ManageSeminarian() {
  const [seminarians, setSeminarians] = useState([]);
  const [search, setSearch] = useState("");
  const [academicYearFilter, setAcademicYearFilter] = useState("");
  const [yearLevelFilter, setYearLevelFilter] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [totalRecords, setTotalRecords] = useState(0);
  const [loading, setLoading] = useState(false);

  // NOTE: `isAddModalOpen`, `setAddModalOpen`, and the Add Seminarian Modal
  // have been removed from ManageSeminarian and moved to Enrollment.jsx.

  useEffect(() => {
    fetchSeminarians();
  }, [search, academicYearFilter, yearLevelFilter, currentPage, itemsPerPage]);

  const fetchSeminarians = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        search,
        academic_year: academicYearFilter,
        year_level: yearLevelFilter,
        page: String(currentPage),
        per_page: String(itemsPerPage),
      });
      const res = await fetch(`/api/registrar/seminarians?${params.toString()}`);
      if (!res.ok) throw new Error("Failed to fetch seminarians");
      const data = await res.json();
      setSeminarians(data.rows || []);
      setTotalRecords(data.total || 0);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const totalPages = Math.max(1, Math.ceil(totalRecords / itemsPerPage));

  return (
    <div className="space-y-6">
      {/* Top Header Section — "Add Seminarian" button removed */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
        <div>
          <h1 className="text-2xl font-extrabold text-slate-900 tracking-tight">
            Manage Seminarians
          </h1>
          <p className="text-xs font-semibold text-slate-500 mt-1">
            Seminarian directory, academic records, and profile management.
          </p>
        </div>

        <div className="flex items-center gap-2.5 flex-wrap">
          <span className="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-teal-50 text-teal-800 border border-teal-200">
            Total: {totalRecords} seminarians
          </span>

          {/* Direct link to the Enrollment module where "Add Seminarian" now lives */}
          <a
            href="/registrar/enrollment"
            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition"
          >
            Go to Enrollment
          </a>
        </div>
      </div>

      {/* Search & Filter Toolbar (Status pills removed) */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setCurrentPage(1);
            }}
            placeholder="Search seminarians by name..."
            className="w-full sm:max-w-xs px-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600"
          />

          <div className="flex items-center gap-2 flex-wrap">
            <select
              value={academicYearFilter}
              onChange={(e) => {
                setAcademicYearFilter(e.target.value);
                setCurrentPage(1);
              }}
              className="px-3 py-2 text-xs font-semibold rounded-xl border border-slate-300 bg-white"
            >
              <option value="">All Academic Years</option>
              <option value="2025-2026">2025-2026</option>
              <option value="2024-2025">2024-2025</option>
            </select>

            <select
              value={yearLevelFilter}
              onChange={(e) => {
                setYearLevelFilter(e.target.value);
                setCurrentPage(1);
              }}
              className="px-3 py-2 text-xs font-semibold rounded-xl border border-slate-300 bg-white"
            >
              <option value="">All Year Levels</option>
              <option value="Propaedeutic Stage">Propaedeutic Stage</option>
              <option value="First Year">First Year</option>
              <option value="Second Year">Second Year</option>
              <option value="Third Year">Third Year</option>
              <option value="Fourth Year">Fourth Year</option>
            </select>
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-extrabold uppercase tracking-wider text-slate-500">
                <th className="py-3 px-4">ID</th>
                <th className="py-3 px-4">Seminarian Name</th>
                <th className="py-3 px-4">Year Level</th>
                <th className="py-3 px-4">Academic Year</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-sm">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-slate-400">
                    Loading seminarian records...
                  </td>
                </tr>
              ) : seminarians.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-slate-400">
                    No seminarian records found.
                  </td>
                </tr>
              ) : (
                seminarians.map((s) => (
                  <tr key={s.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4 font-semibold text-slate-500">#{s.id}</td>
                    <td className="py-3 px-4 font-bold text-slate-900">{s.full_name}</td>
                    <td className="py-3 px-4 text-slate-600">{s.year_level || "—"}</td>
                    <td className="py-3 px-4 text-slate-600">{s.academic_year || "—"}</td>
                    <td className="py-3 px-4">
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        {s.status || "Enrolled"}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-right">
                      <a
                        href={`/registrar/student-profile/${s.seminarian_id}`}
                        className="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-bold text-teal-700 bg-teal-50 hover:bg-teal-100 transition"
                      >
                        Manage Profile
                      </a>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Bottom Pagination Bar */}
        <div className="px-4 py-3 bg-slate-50/60 border-t border-slate-200/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <span className="text-xs font-semibold text-slate-600">
            Page <span className="font-extrabold text-slate-900">{currentPage}</span> of{" "}
            <span className="font-extrabold text-slate-900">{totalPages}</span> ({totalRecords} total)
          </span>
          <div className="inline-flex items-center gap-1">
            <button
              type="button"
              disabled={currentPage <= 1}
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              className="px-3 py-1.5 rounded-lg text-xs font-bold border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40"
            >
              Prev
            </button>
            <button
              type="button"
              disabled={currentPage >= totalPages}
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              className="px-3 py-1.5 rounded-lg text-xs font-bold border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

