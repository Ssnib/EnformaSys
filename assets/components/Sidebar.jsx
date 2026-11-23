import React, { useState } from "react";

/**
 * Sidebar.jsx (Academic Formator Navigation)
 *
 * Cleaned & Consolidated Structure:
 * - Groups "Academic Evaluation", "Evaluations", and "Eval Questions" into a single
 *   collapsible "Evaluations" accordion menu (matching "Academic Records").
 * - Uses consistent Tailwind CSS spacing (`space-y-1.5`), padding (`px-3.5 py-2.5`),
 *   and active/hover states.
 */
const ACADEMIC_FORMATOR_NAV = [
  {
    key: "afms_dashboard",
    label: "Academic Dashboard",
    icon: "fa-solid fa-chart-column",
    href: "/modules/afms_dashboard.php",
  },
  {
    key: "afms_records_group",
    label: "Academic Records",
    icon: "fa-solid fa-users",
    sub: [
      {
        key: "afms_students",
        label: "Students",
        icon: "fa-solid fa-user-graduate",
        href: "/modules/afms_students.php",
      },
      {
        key: "afms_subjects",
        label: "Subjects",
        icon: "fa-solid fa-book",
        href: "/modules/afms_subjects.php",
      },
      {
        key: "afms_grades",
        label: "Grade Management",
        icon: "fa-solid fa-file-pen",
        href: "/modules/afms_grades.php",
      },
      {
        key: "afms_reports",
        label: "Academic Reports",
        icon: "fa-solid fa-file-lines",
        href: "/modules/afms_reports.php",
      },
    ],
  },
  {
    key: "afms_analytics",
    label: "Analytics",
    icon: "fa-solid fa-chart-pie",
    href: "/modules/afms_analytics.php",
  },
  {
    key: "curriculum_master",
    label: "Curriculum",
    icon: "fa-solid fa-book-open",
    href: "/modules/curriculum_master.php",
  },
  // Consolidated "Evaluations" Accordion Group
  {
    key: "afms_evaluations_group",
    label: "Evaluations",
    icon: "fa-solid fa-clipboard-check",
    sub: [
      {
        key: "afe_dashboard",
        label: "Academic Evaluation",
        icon: "fa-solid fa-clipboard-check",
        href: "/modules/afe_dashboard.php",
      },
      {
        key: "afe_evaluations",
        label: "Evaluations",
        icon: "fa-solid fa-list-check",
        href: "/modules/afe_evaluations.php",
      },
      {
        key: "afe_questions",
        label: "Eval Questions",
        icon: "fa-solid fa-list",
        href: "/modules/afe_questions.php",
      },
    ],
  },
  {
    key: "logout",
    label: "Logout",
    icon: "fa-solid fa-arrow-right-from-bracket",
    href: "/logout.php",
  },
];

export default function Sidebar({ activeNav = "afms_dashboard" }) {
  // Automatically expand any accordion group that contains the active link
  const [openGroups, setOpenGroups] = useState(() => {
    const initial = {};
    ACADEMIC_FORMATOR_NAV.forEach((item) => {
      if (item.sub) {
        initial[item.key] = item.sub.some((subItem) => subItem.key === activeNav);
      }
    });
    return initial;
  });

  const toggleGroup = (groupKey) => {
    setOpenGroups((prev) => ({
      ...prev,
      [groupKey]: !prev[groupKey],
    }));
  };

  return (
    <aside className="w-64 bg-white border-r border-[#E8C7C5]/50 flex flex-col h-full select-none">
      <nav
        className="flex-1 overflow-y-auto px-3 py-3 space-y-1.5"
        aria-label="Sidebar Navigation"
      >
        {ACADEMIC_FORMATOR_NAV.map((item) => {
          // Accordion / Dropdown Group
          if (item.sub) {
            const hasActiveChild = item.sub.some((s) => s.key === activeNav);
            const isOpen = !!openGroups[item.key];

            return (
              <div key={item.key} className="space-y-1">
                <button
                  type="button"
                  onClick={() => toggleGroup(item.key)}
                  aria-expanded={isOpen}
                  className={`group w-full flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-200 cursor-pointer ${
                    hasActiveChild
                      ? "bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs"
                      : "text-[#8B7168] hover:bg-[#F8EEEE]/80 hover:text-[#59443F]"
                  }`}
                >
                  <i
                    className={`${item.icon} w-5 text-center text-base shrink-0 ${
                      hasActiveChild
                        ? "text-[#59443F]"
                        : "text-[#8B7168] group-hover:text-[#59443F]"
                    }`}
                  />
                  <span className="flex-1 text-left tracking-wide">
                    {item.label}
                  </span>
                  <i
                    className={`fa-solid fa-chevron-down text-xs transition-transform duration-200 ${
                      isOpen ? "rotate-180 text-[#59443F]" : "text-[#8B7168]"
                    }`}
                  />
                </button>

                {isOpen && (
                  <div className="bg-[#F8EEEE]/60 border-l-2 border-[#E8C7C5] ml-4 pl-3 pr-2 py-1.5 space-y-1 rounded-xl">
                    {item.sub.map((subItem) => {
                      const isSubActive = activeNav === subItem.key;
                      return (
                        <a
                          key={subItem.key}
                          href={subItem.href}
                          className={`group flex items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-xs font-semibold transition-all duration-150 ${
                            isSubActive
                              ? "bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs"
                              : "text-[#8B7168] hover:bg-white hover:text-[#59443F]"
                          }`}
                        >
                          <i
                            className={`${subItem.icon} text-xs w-4 text-center ${
                              isSubActive
                                ? "text-[#59443F]"
                                : "text-[#8B7168] group-hover:text-[#59443F]"
                            }`}
                          />
                          <span className="truncate">{subItem.label}</span>
                        </a>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          }

          // Standalone Link
          const isActive = activeNav === item.key;
          const isLogout = item.key === "logout";

          return (
            <a
              key={item.key}
              href={item.href}
              className={`group flex items-center gap-3.5 rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition-all duration-150 ${
                isActive
                  ? "bg-[#E8C7C5] text-[#59443F] font-bold shadow-xs"
                  : isLogout
                  ? "text-[#8B7168] hover:bg-rose-50/60 hover:text-rose-600"
                  : "text-[#8B7168] hover:bg-[#F8EEEE]/80 hover:text-[#59443F]"
              }`}
            >
              <i
                className={`${item.icon} w-5 text-center text-base shrink-0 ${
                  isActive
                    ? "text-[#59443F]"
                    : "text-[#8B7168] group-hover:text-[#59443F]"
                }`}
              />
              <span className="tracking-wide">{item.label}</span>
            </a>
          );
        })}
      </nav>
    </aside>
  );
}

