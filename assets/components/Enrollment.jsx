import React, { useState, useEffect } from "react";
import AddressSelector from "./AddressSelector";

/**
 * Enrollment.jsx (Renamed from EnrollmentRecords)
 * Refactored:
 * - Added the "Add Seminarian" button in the top header section (Tailwind CSS).
 * - Migrated `isAddModalOpen`, `setAddModalOpen`, form state, submit handler,
 *   and the complete Add Seminarian Modal (including <AddressSelector />) from ManageSeminarian.jsx.
 * - Preserves the append-only semester enrollment workflow (never overwrites past terms).
 */
const INITIAL_ADD_FORM = {
  academic_year: "2025-2026",
  year_level: "Propaedeutic Stage",
  program: "Propaedeutic",
  admission_date: "",
  enrollment_semester: "1st",
  formation_status: "Active",
  student_id: "",
  gender: "Male",
  first_name: "",
  middle_name: "",
  last_name: "",
  suffix: "",
  date_of_birth: "",
  place_of_birth: "",
  age: "",
  civil_status: "Single",
  nationality: "Filipino",
  religion: "Roman Catholic",
  parish_priest: "",
  parish_chaplaincy: "",
  contact_number: "",
  email_address: "",
  permanent_address: "",
  present_address: "",
  region: "",
  province: "",
  municipality: "",
  barangay: "",
  zip_code: "",
  father_name: "",
  father_occupation: "",
  mother_name: "",
  mother_occupation: "",
  guardian_name: "",
  guardian_contact_number: "",
  previous_school: "",
  educational_attainment: "",
  year_graduated: "",
  academic_records: "",
  username: "",
  password: "",
};

export default function Enrollment() {
  // Migrated Add Seminarian Modal State
  const [isAddModalOpen, setAddModalOpen] = useState(false);
  const [addForm, setAddForm] = useState(INITIAL_ADD_FORM);
  const [isSubmittingAdd, setIsSubmittingAdd] = useState(false);

  // Existing New-Semester Enrollment Modal State
  const [isNewTermModalOpen, setNewTermModalOpen] = useState(false);
  const [enrollments, setEnrollments] = useState([]);
  const [bannerMessage, setBannerMessage] = useState(null);

  useEffect(() => {
    fetchEnrollments();
  }, []);

  const fetchEnrollments = async () => {
    try {
      const res = await fetch("/api/registrar/enrollments");
      if (!res.ok) return;
      const data = await res.json();
      setEnrollments(data.rows || []);
    } catch (err) {
      console.error(err);
    }
  };

  // Migrated Input Handler for Add Seminarian Form
  const handleAddInputChange = (e) => {
    const { name, value } = e.target;
    setAddForm((prev) => ({ ...prev, [name]: value }));
  };

  // Handler for Cascading PSGC AddressSelector
  const handleAddressChange = (addr) => {
    setAddForm((prev) => ({
      ...prev,
      region: addr.region?.name || "",
      province: addr.province?.name || "",
      municipality: addr.cityMunicipality?.name || "",
      barangay: addr.barangay?.name || "",
    }));
  };

  // Migrated Submit Handler for Adding & Enrolling a New Seminarian
  const handleAddSeminarianSubmit = async (e) => {
    e.preventDefault();
    setIsSubmittingAdd(true);
    try {
      const res = await fetch("/api/registrar/seminarians", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(addForm),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add seminarian");

      setBannerMessage(
        `Seminarian ${addForm.first_name} ${addForm.last_name} added and enrolled in AY ${addForm.academic_year} (${addForm.enrollment_semester} Sem).`
      );
      setAddModalOpen(false);
      setAddForm(INITIAL_ADD_FORM);
      fetchEnrollments();
    } catch (err) {
      alert(err.message);
    } finally {
      setIsSubmittingAdd(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Header Banner — Includes the relocated "Add Seminarian" button */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-2xl p-6 border border-[#E8C7C5]/60 shadow-sm">
        <div>
          <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-[11px] font-extrabold uppercase tracking-wider mb-2">
            Dedicated Term Enrollment Module
          </div>
          <h1 className="text-2xl font-extrabold text-[#59443F] tracking-tight">
            Enrollment (Semester &amp; Academic Year Management)
          </h1>
          <p className="text-xs font-medium text-[#8B7168] mt-1">
            Every time a seminarian enrolls for a new semester or academic year, a new record is
            appended to <code>enrollments</code>—preserving all past semesters as an intact backup.
          </p>
        </div>

        {/* Action Buttons in Enrollment Header */}
        <div className="flex items-center gap-2.5 flex-wrap">
          {/* Relocated "Add Seminarian" Button */}
          <button
            type="button"
            onClick={() => setAddModalOpen(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-teal-700 hover:bg-teal-800 transition shadow-sm cursor-pointer"
          >
            <span>+ Add Seminarian</span>
          </button>

          <button
            type="button"
            onClick={() => setNewTermModalOpen(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-sm cursor-pointer"
          >
            <span>+ Enroll Student in Term</span>
          </button>
        </div>
      </div>

      {bannerMessage && (
        <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-bold flex items-center justify-between">
          <span>{bannerMessage}</span>
          <button
            type="button"
            onClick={() => setBannerMessage(null)}
            className="text-emerald-700 hover:text-emerald-900 font-extrabold"
          >
            &times;
          </button>
        </div>
      )}

      {/* Migrated Add Seminarian Modal */}
      {isAddModalOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto"
          role="dialog"
          aria-modal="true"
          aria-labelledby="addSeminarianModalTitle"
        >
          <div className="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden">
            {/* Modal Header */}
            <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
              <h3 id="addSeminarianModalTitle" className="text-base font-extrabold">
                Add New Seminarian
              </h3>
              <button
                type="button"
                onClick={() => setAddModalOpen(false)}
                className="text-slate-300 hover:text-white text-xl leading-none"
                aria-label="Close"
              >
                &times;
              </button>
            </div>

            {/* Modal Body */}
            <form
              onSubmit={handleAddSeminarianSubmit}
              className="p-6 bg-slate-100 space-y-5 overflow-y-auto"
            >
              {/* 1. Academic Year & Seminary Information */}
              <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs space-y-4">
                <h4 className="text-xs font-extrabold uppercase tracking-wider text-slate-900 border-b border-slate-100 pb-2">
                  1. Academic Year &amp; Seminary Information
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Academic Year
                    </label>
                    <select
                      name="academic_year"
                      value={addForm.academic_year}
                      onChange={handleAddInputChange}
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white"
                    >
                      <option value="2026-2027">2026-2027</option>
                      <option value="2025-2026">2025-2026</option>
                      <option value="2024-2025">2024-2025</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Year Level *
                    </label>
                    <select
                      name="year_level"
                      value={addForm.year_level}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white"
                    >
                      <option value="Propaedeutic Stage">Propaedeutic Stage</option>
                      <option value="First Year">First Year</option>
                      <option value="Second Year">Second Year</option>
                      <option value="Third Year">Third Year</option>
                      <option value="Fourth Year">Fourth Year</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Semester *
                    </label>
                    <select
                      name="enrollment_semester"
                      value={addForm.enrollment_semester}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white"
                    >
                      <option value="1st">First Semester</option>
                      <option value="2nd">Second Semester</option>
                    </select>
                  </div>
                </div>
              </div>

              {/* 2. Personal Information */}
              <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs space-y-4">
                <h4 className="text-xs font-extrabold uppercase tracking-wider text-slate-900 border-b border-slate-100 pb-2">
                  2. Personal Information
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      First Name *
                    </label>
                    <input
                      type="text"
                      name="first_name"
                      value={addForm.first_name}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Middle Name
                    </label>
                    <input
                      type="text"
                      name="middle_name"
                      value={addForm.middle_name}
                      onChange={handleAddInputChange}
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Last Name *
                    </label>
                    <input
                      type="text"
                      name="last_name"
                      value={addForm.last_name}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"
                    />
                  </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Date of Birth *
                    </label>
                    <input
                      type="date"
                      name="date_of_birth"
                      value={addForm.date_of_birth}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Gender *
                    </label>
                    <select
                      name="gender"
                      value={addForm.gender}
                      onChange={handleAddInputChange}
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 bg-white"
                    >
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Contact Number
                    </label>
                    <input
                      type="text"
                      name="contact_number"
                      value={addForm.contact_number}
                      onChange={handleAddInputChange}
                      className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300"
                    />
                  </div>
                </div>
              </div>

              {/* 3. Address Information (Uses Reusable PSGC AddressSelector.jsx) */}
              <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs space-y-4">
                <h4 className="text-xs font-extrabold uppercase tracking-wider text-slate-900 border-b border-slate-100 pb-2">
                  3. Philippine Address (PSGC Cascading Dropdowns)
                </h4>
                <AddressSelector onChange={handleAddressChange} />
              </div>

              {/* Modal Footer */}
              <div className="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-200">
                <button
                  type="button"
                  onClick={() => setAddModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmittingAdd}
                  className="px-5 py-2 rounded-xl text-xs font-bold bg-teal-700 text-white hover:bg-teal-800 shadow-xs disabled:opacity-50"
                >
                  {isSubmittingAdd ? "Saving..." : "Save Seminarian"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

