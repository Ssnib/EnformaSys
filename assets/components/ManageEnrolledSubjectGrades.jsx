import React, { useState, useEffect } from "react";

/**
 * Automated Remarks Helper
 *
 * Rules:
 * 1. If `prelim` has a grade, and BOTH `midterm` and `final` are empty -> "Dropped"
 * 2. If `prelim` has a grade, and EITHER `midterm` OR `final` has a grade (but lacks one) -> "Incomplete"
 * 3. If `prelim`, `midterm`, AND `final` all have grades -> "Complete"
 */
export function computeSubjectRemark(prelim, midterm, finalGrade) {
  const hasPrelim =
    prelim !== null && prelim !== undefined && String(prelim).trim() !== "";
  const hasMidterm =
    midterm !== null && midterm !== undefined && String(midterm).trim() !== "";
  const hasFinal =
    finalGrade !== null &&
    finalGrade !== undefined &&
    String(finalGrade).trim() !== "";

  if (hasPrelim && hasMidterm && hasFinal) {
    return "Complete";
  }
  if (hasPrelim && (hasMidterm || hasFinal)) {
    return "Incomplete";
  }
  if (hasPrelim && !hasMidterm && !hasFinal) {
    return "Dropped";
  }
  if (!hasPrelim && (hasMidterm || hasFinal)) {
    return "Incomplete";
  }
  return "";
}

/**
 * ManageEnrolledSubjectGrades.jsx
 *
 * Refactored:
 * - Removed all GPA / Final % calculations, state, and UI elements.
 * - Removed the manual "Status" dropdown.
 * - Implemented an automated, read-only "Remarks" field that updates reactively
 *   whenever `prelim`, `midterm`, or `final` grade inputs change.
 */
export default function ManageEnrolledSubjectGrades({
  student = { id: 1, fullName: "Seminarian Juan dela Cruz" },
  academicYear = "2025-2026",
  semester = "1st",
  initialSubjects = [],
  onSave,
  onClose,
}) {
  const [subjects, setSubjects] = useState(() =>
    initialSubjects.map((subj) => ({
      ...subj,
      prelim: subj.prelim ?? "",
      midterm: subj.midterm ?? "",
      final: subj.final ?? "",
      remarks: computeSubjectRemark(subj.prelim, subj.midterm, subj.final),
    }))
  );
  const [isSaving, setIsSaving] = useState(false);

  // Sync remarks automatically via useEffect if initialSubjects prop updates
  useEffect(() => {
    setSubjects(
      initialSubjects.map((subj) => ({
        ...subj,
        prelim: subj.prelim ?? "",
        midterm: subj.midterm ?? "",
        final: subj.final ?? "",
        remarks: computeSubjectRemark(subj.prelim, subj.midterm, subj.final),
      }))
    );
  }, [initialSubjects]);

  // Handle manual grade input change & automatically derive Remarks
  const handleGradeChange = (subjectId, field, rawValue) => {
    setSubjects((prevSubjects) =>
      prevSubjects.map((row) => {
        if (row.subject_id !== subjectId) return row;

        const updatedRow = {
          ...row,
          [field]: rawValue,
        };

        // Automatically recalculate Remarks based on prelim, midterm, and final presence
        updatedRow.remarks = computeSubjectRemark(
          updatedRow.prelim,
          updatedRow.midterm,
          updatedRow.final
        );

        return updatedRow;
      })
    );
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      const payload = {
        student_id: student.id,
        academic_year: academicYear,
        semester,
        grades: subjects.map((s) => ({
          subject_id: s.subject_id,
          prelim: s.prelim !== "" ? Number(s.prelim) : null,
          midterm: s.midterm !== "" ? Number(s.midterm) : null,
          final: s.final !== "" ? Number(s.final) : null,
          remarks: computeSubjectRemark(s.prelim, s.midterm, s.final),
        })),
      };

      if (onSave) {
        await onSave(payload);
      } else {
        await fetch("/api/academic/enrolled-subject-grades", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
      }
    } finally {
      setIsSaving(false);
    }
  };

  // Badge styling helper for read-only Remarks field
  const getRemarkInputClasses = (remark) => {
    switch (remark) {
      case "Complete":
        return "bg-emerald-50 text-emerald-800 border-emerald-200";
      case "Incomplete":
        return "bg-amber-50 text-amber-800 border-amber-200";
      case "Dropped":
        return "bg-rose-50 text-rose-800 border-rose-200";
      default:
        return "bg-slate-50 text-slate-400 border-slate-200";
    }
  };

  const completeCount = subjects.filter((s) => s.remarks === "Complete").length;

  return (
    <div className="bg-white rounded-2xl max-w-5xl w-full shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]">
      {/* Modal Header */}
      <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
        <div>
          <h2 className="text-lg font-extrabold flex items-center gap-2">
            <span>Manage Enrolled Subject Grades</span>
          </h2>
          <p className="text-xs text-slate-400 mt-0.5">
            <span className="text-slate-100 font-bold">{student.fullName}</span>{" "}
            (#{student.id}) &middot;{" "}
            <span className="text-sky-400 font-semibold">
              {academicYear} &middot; {semester} Semester
            </span>
          </p>
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            className="text-slate-400 hover:text-white text-2xl leading-none cursor-pointer"
            aria-label="Close modal"
          >
            &times;
          </button>
        )}
      </div>

      {/* Modal Body */}
      <form onSubmit={handleSubmit} className="p-6 overflow-y-auto flex-1 space-y-4">
        {/* Info Banner (GPA formula removed) */}
        <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-medium">
          Strict Enrollment Filtering Active: Displaying{" "}
          <strong>{subjects.length}</strong> officially enrolled subjects.{" "}
          <strong>Remarks</strong> are automatically determined from Prelim, Midterm, and
          Final grade inputs (<em>Complete</em>, <em>Incomplete</em>, or{" "}
          <em>Dropped</em>).
        </div>

        {/* Enrolled Subject Grades Table (GPA & Manual Status columns removed) */}
        <div className="overflow-x-auto border border-slate-200 rounded-xl">
          <table className="w-full text-left border-collapse text-sm">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs font-extrabold uppercase tracking-wider">
                <th className="py-3 px-4 min-w-[240px]">Enrolled Subject</th>
                <th className="py-3 px-3 text-center w-28">Prelim</th>
                <th className="py-3 px-3 text-center w-28">Midterm</th>
                <th className="py-3 px-3 text-center w-28">Final</th>
                <th className="py-3 px-4 min-w-[180px]">Remarks (Automated)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {subjects.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-400">
                    No officially enrolled subjects found for this term.
                  </td>
                </tr>
              ) : (
                subjects.map((item) => (
                  <tr key={item.subject_id} className="hover:bg-slate-50/70 transition">
                    <td className="py-3.5 px-4">
                      <div className="font-bold text-slate-900">{item.subject_code}</div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        {item.subject_name}
                      </div>
                      <span className="inline-block mt-1 px-2 py-0.5 rounded-md text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                        {item.units} units
                      </span>
                    </td>

                    {/* Prelim Grade Input */}
                    <td className="py-3.5 px-3 text-center">
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        value={item.prelim}
                        onChange={(e) =>
                          handleGradeChange(item.subject_id, "prelim", e.target.value)
                        }
                        placeholder="—"
                        className="w-22 px-2.5 py-1.5 text-center font-bold text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600"
                      />
                    </td>

                    {/* Midterm Grade Input */}
                    <td className="py-3.5 px-3 text-center">
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        value={item.midterm}
                        onChange={(e) =>
                          handleGradeChange(item.subject_id, "midterm", e.target.value)
                        }
                        placeholder="—"
                        className="w-22 px-2.5 py-1.5 text-center font-bold text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600"
                      />
                    </td>

                    {/* Final Grade Input */}
                    <td className="py-3.5 px-3 text-center">
                      <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        value={item.final}
                        onChange={(e) =>
                          handleGradeChange(item.subject_id, "final", e.target.value)
                        }
                        placeholder="—"
                        className="w-22 px-2.5 py-1.5 text-center font-bold text-sm rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600"
                      />
                    </td>

                    {/* Read-Only Automated Remarks Field */}
                    <td className="py-3.5 px-4">
                      <input
                        type="text"
                        readOnly
                        value={item.remarks}
                        placeholder="—"
                        className={`w-full px-3 py-1.5 text-xs font-extrabold rounded-lg border cursor-not-allowed focus:outline-none ${getRemarkInputClasses(
                          item.remarks
                        )}`}
                      />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Modal Footer (Projected Term GPA removed) */}
        <div className="pt-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-5 text-xs font-semibold text-slate-600">
            <span>
              Enrolled Subjects:{" "}
              <strong className="text-slate-900">{subjects.length}</strong>
            </span>
            <span>
              Complete:{" "}
              <strong className="text-slate-900">{completeCount}</strong>/
              {subjects.length}
            </span>
          </div>

          <div className="flex items-center gap-2.5">
            {onClose && (
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition cursor-pointer"
              >
                Cancel
              </button>
            )}
            <button
              type="submit"
              disabled={isSaving}
              className="px-5 py-2 rounded-xl text-xs font-extrabold bg-[#59443F] text-white hover:bg-[#463430] transition shadow-sm disabled:opacity-50 cursor-pointer"
            >
              {isSaving ? "Saving..." : "Save All Grades"}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}

