/**
 * Academic Calendar Component for EnformaSys
 * Standardized across Admin, Registrar, and Management Dashboards.
 * Visually and structurally unified to match the Seminarian (Student) Calendar design:
 * - Pure white floating card with rounded-3xl, border-[#E8C7C5]/50, p-5 sm:p-6, shadow-soft
 * - Single-row minimalist header with font-heading font-extrabold text-[#59443F] and rounded-lg nav buttons
 * - 7-Column single-letter weekday header (S, M, T, W, T, F, S) in text-[#8B7168]
 * - Compact h-9 rounded-xl day cells with #E8C7C5 today pill, #F8EEEE hover/selected, and #D6A6A6 dots
 * - Soft #F8EEEE/60 rounded-xl events drawer with read-only badge and timetable link
 */
class AcademicCalendar {
    constructor(containerId, options = {}) {
        this.container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
        if (!this.container) return;

        this.baseUrl = options.baseUrl || 'modules/ajax.php';
        this.timetableUrl = options.timetableUrl || 'modules/timetable.php';

        // Read-only and permission options
        this.readOnly = options.readOnly !== undefined ? Boolean(options.readOnly) : false;
        this.selectable = options.selectable !== undefined ? Boolean(options.selectable) : !this.readOnly;
        this.canManage = options.canManage !== undefined ? Boolean(options.canManage) : !this.readOnly;

        // Strict read-only enforcement
        if (this.readOnly) {
            this.canManage = false;
            this.selectable = false;
        }

        this.enableDummyData = options.enableDummyData !== undefined ? Boolean(options.enableDummyData) : true;
        this.dateClick = (!this.readOnly && typeof options.dateClick === 'function') ? options.dateClick : null;
        this.eventClick = typeof options.eventClick === 'function' ? options.eventClick : null;

        const now = new Date();
        this.currentYear = now.getFullYear();
        this.currentMonth = now.getMonth(); // 0 = Jan, 9 = Oct
        this.todayDate = now.getDate();
        this.todayMonth = now.getMonth();
        this.todayYear = now.getFullYear();
        this.todayStr = this.formatDateKey(this.todayYear, this.todayMonth + 1, this.todayDate);
        this.selectedDateStr = this.todayStr;

        this.currentFilter = 'All'; // 'All' | 'Enrollment' | 'Academic' | 'Holiday' | 'Formation'
        this.currentView = 'grid'; // 'grid' | 'agenda'

        this.events = {};
        this.liveEvents = {};

        this.categoryStyles = {
            Enrollment: {
                bg: 'bg-emerald-50',
                text: 'text-emerald-700',
                border: 'border-emerald-200',
                dot: 'bg-emerald-500',
                hex: '#10b981',
                badgeClass: 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
                icon: 'fa-solid fa-user-plus',
                label: 'Enrollment'
            },
            Academic: {
                bg: 'bg-[#F8EEEE]',
                text: 'text-[#59443F]',
                border: 'border-[#E8C7C5]/60',
                dot: 'bg-[#D6A6A6]',
                hex: '#D6A6A6',
                badgeClass: 'bg-[#F8EEEE] text-[#59443F] border-[#E8C7C5]/60',
                icon: 'fa-solid fa-graduation-cap',
                label: 'Academic'
            },
            Holiday: {
                bg: 'bg-rose-50',
                text: 'text-rose-700',
                border: 'border-rose-200',
                dot: 'bg-rose-500',
                hex: '#ef4444',
                badgeClass: 'bg-rose-50 text-rose-700 border-rose-200/80',
                icon: 'fa-solid fa-umbrella-beach',
                label: 'Holiday'
            },
            Formation: {
                bg: 'bg-[#F8EEEE]',
                text: 'text-[#59443F]',
                border: 'border-[#E8C7C5]/60',
                dot: 'bg-[#D6A6A6]',
                hex: '#D6A6A6',
                badgeClass: 'bg-[#F8EEEE] text-[#59443F] border-[#E8C7C5]/60',
                icon: 'fa-solid fa-cross',
                label: 'Formation'
            },
            Church: {
                bg: 'bg-amber-50',
                text: 'text-amber-800',
                border: 'border-amber-200',
                dot: 'bg-amber-500',
                hex: '#f59e0b',
                badgeClass: 'bg-amber-50 text-amber-800 border-amber-200/80',
                icon: 'fa-solid fa-church',
                label: 'Church / Liturgy'
            },
            General: {
                bg: 'bg-[#F8EEEE]',
                text: 'text-[#59443F]',
                border: 'border-[#E8C7C5]/60',
                dot: 'bg-[#D6A6A6]',
                hex: '#D6A6A6',
                badgeClass: 'bg-[#F8EEEE] text-[#59443F] border-[#E8C7C5]/60',
                icon: 'fa-solid fa-calendar-day',
                label: 'General'
            }
        };

        this.monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];

        this.init();
    }

    init() {
        this.renderStructure();
        this.loadEvents();
    }

    renderStructure() {
        this.container.innerHTML = `
            <div class="academic-calendar-widget w-full min-w-0 bg-white rounded-3xl border border-[#E8C7C5]/50 p-4 sm:p-5 xl:p-6 shadow-lg font-['Inter',sans-serif] transition-all">
                <!-- Header: Month Title & Nav Buttons (Matches Student Calendar) -->
                <div class="flex flex-wrap items-center justify-between mb-3 gap-2">
                    <div class="min-w-0">
                        <h4 class="ac-month-label font-heading font-extrabold text-sm text-[#59443F] tracking-tight truncate"></h4>
                        <span class="ac-event-count text-[10px] font-semibold text-[#8B7168]"></span>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <a href="${this.timetableUrl}" class="ac-full-timetable-btn inline-flex items-center gap-1 px-2 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold transition border border-[#E8C7C5]/60 shadow-2xs" title="Open Full View Timetable">
                            <i class="fa-solid fa-calendar-days text-[10px] text-[#D6A6A6]"></i>
                            <span class="hidden 2xl:inline">Full View Timetable</span>
                        </a>
                        <button type="button" class="ac-btn-prev w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Previous Month" title="Previous Month">
                            <i class="fa-solid fa-chevron-left text-[10px]"></i>
                        </button>
                        <button type="button" class="ac-btn-today px-2 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold flex items-center justify-center transition cursor-pointer" title="Jump to Today">
                            Today
                        </button>
                        <button type="button" class="ac-btn-next w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Next Month" title="Next Month">
                            <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </button>
                    </div>
                </div>

                <!-- Secondary Subheader: Subtle Category Filter Pills & Month/Agenda Switcher -->
                <div class="flex items-center justify-between gap-1.5 mb-3 pb-2.5 border-b border-[#E8C7C5]/30">
                    <!-- Filter pills -->
                    <div class="flex items-center gap-1 overflow-x-auto text-[10px] font-bold scrollbar-none py-0.5">
                        <button type="button" class="ac-filter-pill px-2.5 py-0.5 rounded-full transition active bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 cursor-pointer shadow-2xs" data-filter="All">All</button>
                        <button type="button" class="ac-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Academic">Academic</button>
                        <button type="button" class="ac-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Holidays">Holidays</button>
                        <button type="button" class="ac-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Events">Events</button>
                    </div>
                    <!-- View Switcher -->
                    <div class="inline-flex p-0.5 rounded-lg bg-[#F8EEEE] border border-[#E8C7C5]/50 text-[10px] font-bold shrink-0">
                        <button type="button" class="ac-tab-grid px-2 py-0.5 rounded-md transition bg-white text-[#59443F] shadow-2xs cursor-pointer" data-view="grid" title="Month Grid View"><i class="fa-solid fa-table-cells text-[9px]"></i></button>
                        <button type="button" class="ac-tab-agenda px-2 py-0.5 rounded-md transition text-[#8B7168] hover:text-[#59443F] cursor-pointer" data-view="agenda" title="Agenda List View"><i class="fa-solid fa-list-ul text-[9px]"></i></button>
                    </div>
                </div>

                <!-- Main Content Area: Grid View Container -->
                <div class="ac-grid-container">
                    <!-- 7-Column Day Names Header (Matches Student Calendar) -->
                    <div class="grid grid-cols-7 gap-1 text-center mb-2">
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">M</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">W</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">F</span>
                        <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                    </div>

                    <!-- Calendar Grid of Days -->
                    <div class="ac-days-grid grid grid-cols-7 gap-1 text-center text-xs"></div>
                </div>

                <!-- Main Content Area: Agenda List View Container (Hidden by default) -->
                <div class="ac-agenda-container hidden">
                    <div class="ac-agenda-list space-y-1.5 max-h-48 overflow-y-auto pr-1"></div>
                </div>

                <!-- Selected Date Event Details Drawer (Matches Student Calendar) -->
                <div class="ac-details-drawer mt-4 pt-3 border-t border-[#E8C7C5]/40 text-xs"></div>

                <!-- Bottom Footer Toolbar -->
                <div class="mt-3 pt-2.5 border-t border-[#E8C7C5]/30 flex flex-wrap items-center justify-between gap-2 text-[10px]">
                    <div class="flex items-center gap-1.5 text-[#8B7168]">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#E8C7C5] ring-1 ring-[#D6A6A6] inline-block"></span>
                        <span>Today</span>
                    </div>
                    <div class="flex items-center gap-3">
                        ${(this.canManage && !this.readOnly) ? `
                            <a href="${this.timetableUrl}?prefill_date=${this.selectedDateStr}" class="font-bold text-[#59443F] hover:text-[#8B7168] transition inline-flex items-center gap-1">
                                <i class="fa-solid fa-plus text-[9px] text-[#D6A6A6]"></i> Add Event
                            </a>
                        ` : `
                            <span class="text-[#8B7168] font-medium inline-flex items-center gap-1">
                                <i class="fa-solid fa-lock text-[9px] text-[#D6A6A6]"></i> Read-Only View
                            </span>
                        `}
                        <a href="${this.timetableUrl}" class="font-bold text-[#59443F] hover:text-[#8B7168] transition inline-flex items-center gap-1">
                            Full Timetable <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                        </a>
                    </div>
                </div>
            </div>
        `;

        // Cache elements
        this.prevBtn = this.container.querySelector('.ac-btn-prev');
        this.nextBtn = this.container.querySelector('.ac-btn-next');
        this.todayBtn = this.container.querySelector('.ac-btn-today');
        this.monthLabelEl = this.container.querySelector('.ac-month-label');
        this.eventCountEl = this.container.querySelector('.ac-event-count');
        this.daysGridEl = this.container.querySelector('.ac-days-grid');
        this.gridContainerEl = this.container.querySelector('.ac-grid-container');
        this.agendaContainerEl = this.container.querySelector('.ac-agenda-container');
        this.agendaListEl = this.container.querySelector('.ac-agenda-list');
        this.detailsDrawerEl = this.container.querySelector('.ac-details-drawer');

        this.tabGrid = this.container.querySelector('.ac-tab-grid');
        this.tabAgenda = this.container.querySelector('.ac-tab-agenda');

        // Bind Events
        this.prevBtn.addEventListener('click', () => this.navigateMonth(-1));
        this.nextBtn.addEventListener('click', () => this.navigateMonth(1));
        this.todayBtn.addEventListener('click', () => this.jumpToToday());

        this.tabGrid.addEventListener('click', () => this.switchView('grid'));
        this.tabAgenda.addEventListener('click', () => this.switchView('agenda'));

        this.container.querySelectorAll('.ac-filter-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                const filter = btn.getAttribute('data-filter');
                this.setFilter(filter);
            });
        });
    }

    switchView(view) {
        this.currentView = view;
        if (view === 'grid') {
            this.tabGrid.className = 'ac-tab-grid px-2 py-0.5 rounded-md transition bg-white text-[#59443F] shadow-2xs font-bold cursor-pointer';
            this.tabAgenda.className = 'ac-tab-agenda px-2 py-0.5 rounded-md transition text-[#8B7168] hover:text-[#59443F] font-bold cursor-pointer';
            this.gridContainerEl.classList.remove('hidden');
            this.agendaContainerEl.classList.add('hidden');
        } else {
            this.tabAgenda.className = 'ac-tab-agenda px-2 py-0.5 rounded-md transition bg-white text-[#59443F] shadow-2xs font-bold cursor-pointer';
            this.tabGrid.className = 'ac-tab-grid px-2 py-0.5 rounded-md transition text-[#8B7168] hover:text-[#59443F] font-bold cursor-pointer';
            this.gridContainerEl.classList.add('hidden');
            this.agendaContainerEl.classList.remove('hidden');
            this.renderAgenda();
        }
    }

    setFilter(filter) {
        this.currentFilter = filter;
        this.container.querySelectorAll('.ac-filter-pill').forEach(btn => {
            const f = btn.getAttribute('data-filter');
            if (f === filter) {
                btn.className = 'ac-filter-pill px-2.5 py-0.5 rounded-full transition active bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 font-bold cursor-pointer shadow-2xs';
            } else {
                btn.className = 'ac-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 font-bold cursor-pointer';
            }
        });

        this.renderGrid();
        if (this.currentView === 'agenda') {
            this.renderAgenda();
        }
        this.renderDetails(this.selectedDateStr);
    }

    navigateMonth(delta) {
        this.currentMonth += delta;
        if (this.currentMonth > 11) {
            this.currentMonth = 0;
            this.currentYear++;
        } else if (this.currentMonth < 0) {
            this.currentMonth = 11;
            this.currentYear--;
        }
        this.loadEvents();
    }

    jumpToToday() {
        const now = new Date();
        this.currentYear = now.getFullYear();
        this.currentMonth = now.getMonth();
        this.selectedDateStr = this.todayStr;
        this.loadEvents();
    }

    getSampleEventsForMonth(year, monthIndex) {
        const y = year;
        const m = monthIndex + 1;
        const daysInMonth = new Date(y, m, 0).getDate();

        return [
            {
                id: 'dummy-1',
                event: 'Start of Enrollment',
                type: 'Enrollment',
                date: this.formatDateKey(y, m, 5),
                time: '08:00 AM',
                end_time: '05:00 PM',
                note: 'Registration and subject enlistment opens for Seminarians. Dean and Registrar validation required.'
            },
            {
                id: 'dummy-2',
                event: 'Midterm Examinations',
                type: 'Academic',
                date: this.formatDateKey(y, m, Math.min(19, daysInMonth)),
                time: '08:30 AM',
                end_time: '04:30 PM',
                note: 'Mid-semester major written and oral academic examinations for Philosophy and Theology courses.'
            },
            {
                id: 'dummy-3',
                event: 'Seminary Academic Holiday',
                type: 'Holiday',
                date: this.formatDateKey(y, m, daysInMonth),
                time: 'All Day',
                end_time: '',
                note: 'Institutional non-working holiday and community rest day.'
            },
            {
                id: 'dummy-4',
                event: 'General Formation Assembly',
                type: 'Formation',
                date: this.formatDateKey(y, m, Math.min(12, daysInMonth)),
                time: '09:00 AM',
                end_time: '12:00 PM',
                note: 'Seminary conference, human and pastoral formation spiritual direction.'
            },
            {
                id: 'dummy-5',
                event: 'Faculty Grade Encoding Deadline',
                type: 'Academic',
                date: this.formatDateKey(y, m, Math.min(24, daysInMonth)),
                time: '05:00 PM',
                end_time: '',
                note: 'Deadline for academic professors to encode mid-term/final grades into EnformaSys portal.'
            }
        ];
    }

    async loadEvents() {
        const queryMonth = this.currentMonth + 1;
        let fetchedEvents = [];

        try {
            const separator = this.baseUrl.includes('?') ? '&' : '?';
            const url = `${this.baseUrl}${separator}action=get_events_for_month&year=${this.currentYear}&month=${queryMonth}`;
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (res.ok) {
                const data = await res.json();
                if (data && data.events && Array.isArray(data.events)) {
                    fetchedEvents = data.events;
                }
            }
        } catch (e) {
            // Silently fall back to dummy samples
        }

        this.events = {};

        // 1. Load Dummy/Sample Events if enabled
        if (this.enableDummyData) {
            const dummyList = this.getSampleEventsForMonth(this.currentYear, this.currentMonth);
            dummyList.forEach(ev => {
                if (!this.events[ev.date]) this.events[ev.date] = [];
                this.events[ev.date].push(ev);
            });
        }

        // 2. Merge live database events
        if (fetchedEvents && fetchedEvents.length > 0) {
            fetchedEvents.forEach(ev => {
                if (!this.events[ev.date]) this.events[ev.date] = [];
                const exists = this.events[ev.date].some(x => x.event.toLowerCase() === ev.event.toLowerCase());
                if (!exists) {
                    this.events[ev.date].push(ev);
                }
            });
        }

        this.renderGrid();
        if (this.currentView === 'agenda') {
            this.renderAgenda();
        }
        this.renderDetails(this.selectedDateStr);
    }

    getFilteredEventsForDate(dateStr) {
        const list = this.events[dateStr] || [];
        if (!this.currentFilter || this.currentFilter === 'All') return list;
        return list.filter(ev => {
            const type = (ev.type || '').toLowerCase();
            const filter = this.currentFilter.toLowerCase();
            if (filter === 'events') {
                return !type.includes('holiday');
            }
            if (filter === 'holidays' || filter === 'holiday') {
                return type.includes('holiday');
            }
            if (filter === 'academic') {
                return type.includes('academic') || type.includes('enrollment') || type.includes('exam');
            }
            return type.includes(filter) || filter.includes(type);
        });
    }

    getAllMonthEvents() {
        const all = [];
        const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
        for (let d = 1; d <= daysInMonth; d++) {
            const dKey = this.formatDateKey(this.currentYear, this.currentMonth + 1, d);
            const evs = this.getFilteredEventsForDate(dKey);
            evs.forEach(ev => all.push(ev));
        }
        return all;
    }

    renderGrid() {
        this.monthLabelEl.textContent = `${this.monthNames[this.currentMonth]} ${this.currentYear}`;
        const monthEvents = this.getAllMonthEvents();
        this.eventCountEl.textContent = `• ${monthEvents.length} Event${monthEvents.length === 1 ? '' : 's'}`;

        const firstDayOfWeek = new Date(this.currentYear, this.currentMonth, 1).getDay(); // 0 = Sun
        const daysInCurrentMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
        const daysInPrevMonth = new Date(this.currentYear, this.currentMonth, 0).getDate();

        let cellsHtml = '';

        // Previous Month Padding Days (Matching Student Calendar)
        for (let i = firstDayOfWeek - 1; i >= 0; i--) {
            const dayNum = daysInPrevMonth - i;
            cellsHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${dayNum}</div>`;
        }

        // Active Month Days (Matching Student Calendar)
        for (let day = 1; day <= daysInCurrentMonth; day++) {
            const dateStr = this.formatDateKey(this.currentYear, this.currentMonth + 1, day);
            const isToday = (day === this.todayDate && this.currentMonth === this.todayMonth && this.currentYear === this.todayYear);
            const isSelected = dateStr === this.selectedDateStr;
            const dayEvents = this.getFilteredEventsForDate(dateStr);
            const hasEvents = dayEvents.length > 0;

            let dayClasses = "ac-day-cell h-9 flex flex-col items-center justify-center rounded-xl cursor-pointer transition relative select-none ";
            if (isToday) {
                dayClasses += "bg-[#E8C7C5] text-[#59443F] font-black shadow-xs ring-1 ring-[#D6A6A6] ";
            } else if (isSelected) {
                dayClasses += "bg-[#F8EEEE] text-[#59443F] font-bold border border-[#E8C7C5] ";
            } else {
                dayClasses += "text-[#59443F] hover:bg-[#F8EEEE] font-semibold ";
            }

            // Indicator dots matching Student Calendar
            let dotsHtml = '';
            if (hasEvents) {
                dotsHtml = `<div class="flex items-center justify-center gap-0.5 mt-0.5">` + 
                    dayEvents.slice(0, 3).map(() => `<span class="w-1 h-1 rounded-full ${isToday ? 'bg-[#59443F]' : 'bg-[#D6A6A6]'} inline-block"></span>`).join('') + 
                `</div>`;
            }

            const tooltip = hasEvents 
                ? `${dateStr}: ${dayEvents.map(e => `[${e.type}] ${e.event}`).join(', ')}`
                : dateStr;

            cellsHtml += `
                <div class="${dayClasses}" data-date="${dateStr}" title="${this.escapeHtml(tooltip)}">
                    <span class="text-xs leading-none">${day}</span>
                    <div class="h-1.5 flex items-center justify-center">${dotsHtml}</div>
                </div>
            `;
        }

        // Next Month Trailing Padding Days
        const totalRendered = firstDayOfWeek + daysInCurrentMonth;
        const trailingDays = (7 - (totalRendered % 7)) % 7;
        for (let i = 1; i <= trailingDays; i++) {
            cellsHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${i}</div>`;
        }

        this.daysGridEl.innerHTML = cellsHtml;

        // Attach Click Listeners
        this.daysGridEl.querySelectorAll('.ac-day-cell').forEach(cell => {
            cell.addEventListener('click', (e) => {
                const dateKey = cell.getAttribute('data-date');
                this.selectedDateStr = dateKey;
                this.renderGrid();
                this.renderDetails(dateKey);

                if (!this.readOnly && this.selectable && typeof this.dateClick === 'function') {
                    this.dateClick({ dateStr: dateKey, dayEl: cell, jsEvent: e });
                }
            });
        });
    }

    renderAgenda() {
        const events = this.getAllMonthEvents();
        if (events.length === 0) {
            this.agendaListEl.innerHTML = `
                <div class="p-3 rounded-xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/30 text-center text-[#8B7168] text-[11px] italic">
                    No events found for this filter in ${this.monthNames[this.currentMonth]} ${this.currentYear}.
                </div>
            `;
            return;
        }

        events.sort((a, b) => a.date.localeCompare(b.date));

        let html = '';
        events.forEach(ev => {
            const dateObj = new Date(ev.date + 'T00:00:00');
            const dateBadge = !isNaN(dateObj.getTime())
                ? dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                : ev.date;

            html += `
                <div class="ac-agenda-item p-2.5 rounded-xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition flex items-center justify-between gap-2 cursor-pointer" data-event-id="${this.escapeHtml(ev.id || '')}" data-event-date="${ev.date}" title="Click to view details">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#D6A6A6] shrink-0"></span>
                        <div class="min-w-0">
                            <span class="font-bold text-xs text-[#59443F] truncate block">${this.escapeHtml(ev.event)}</span>
                            <span class="text-[10px] text-[#8B7168]">${dateBadge} ${ev.time ? '• ' + this.escapeHtml(ev.time) : ''}</span>
                        </div>
                    </div>
                    <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 shrink-0">
                        ${this.escapeHtml(ev.type || 'General')}
                    </span>
                </div>
            `;
        });

        this.agendaListEl.innerHTML = html;

        this.agendaListEl.querySelectorAll('.ac-agenda-item').forEach(item => {
            item.addEventListener('click', () => {
                const evId = item.getAttribute('data-event-id');
                const evDate = item.getAttribute('data-event-date');
                const evList = this.events[evDate] || [];
                const found = evList.find(x => String(x.id) === String(evId)) || evList[0];
                if (found) {
                    this.openEventDetailsModal(found);
                }
            });
        });
    }

    renderDetails(dateStr) {
        if (!this.detailsDrawerEl) return;
        const dayEvents = this.getFilteredEventsForDate(dateStr);
        const dateObj = new Date(dateStr + 'T00:00:00');
        const formattedDate = !isNaN(dateObj.getTime())
            ? dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
            : dateStr;

        if (dayEvents.length === 0) {
            this.detailsDrawerEl.innerHTML = `
                <div class="flex items-center justify-between mb-2">
                    <span class="font-bold text-[#59443F] text-[11px] uppercase tracking-wider">${formattedDate} Events</span>
                    <span class="text-[9px] font-semibold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded border border-[#E8C7C5]/40 flex items-center gap-1">
                        ${this.readOnly ? '<i class="fa-solid fa-lock text-[8px] text-[#8B7168]"></i> Read-Only' : '0 Events'}
                    </span>
                </div>
                <div class="p-2.5 rounded-xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/30 text-center text-[#8B7168] text-[11px] italic">
                    No seminary events scheduled for this day.
                </div>
            `;
            return;
        }

        let eventsHtml = dayEvents.map(ev => {
            let timeStr = ev.time ? ev.time.substring(0, 5) : 'All Day';
            return `
                <div class="ac-drawer-event p-2.5 rounded-xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition cursor-pointer" data-event-id="${this.escapeHtml(ev.id || '')}" data-event-date="${dateStr}" title="Click to view details">
                    <div class="flex items-center justify-between gap-1 mb-1">
                        <span class="font-bold text-xs text-[#59443F] truncate">${this.escapeHtml(ev.event)}</span>
                        <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 shrink-0">
                            ${this.escapeHtml(ev.type || 'General')}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-[#8B7168]">
                        <span class="flex items-center gap-1">
                            <i class="fa-regular fa-clock text-[9px] text-[#D6A6A6]"></i>
                            ${this.escapeHtml(timeStr)}
                        </span>
                        ${ev.note ? `<span class="truncate max-w-[130px] italic">${this.escapeHtml(ev.note)}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        this.detailsDrawerEl.innerHTML = `
            <div class="flex items-center justify-between mb-2">
                <span class="font-bold text-[#59443F] text-[11px] uppercase tracking-wider">${formattedDate} Events</span>
                <span class="text-[9px] font-semibold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded border border-[#E8C7C5]/40 flex items-center gap-1">
                    ${this.readOnly ? '<i class="fa-solid fa-lock text-[8px] text-[#8B7168]"></i> Read-Only' : '<i class="fa-solid fa-calendar-check text-[8px] text-[#D6A6A6]"></i> ' + dayEvents.length + ' Event' + (dayEvents.length > 1 ? 's' : '')}
                </span>
            </div>
            <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                ${eventsHtml}
            </div>
        `;

        this.detailsDrawerEl.querySelectorAll('.ac-drawer-event').forEach(el => {
            el.addEventListener('click', () => {
                const id = el.getAttribute('data-event-id');
                const found = dayEvents.find(x => String(x.id) === String(id)) || dayEvents[0];
                if (found) {
                    this.openEventDetailsModal(found);
                }
            });
        });
    }

    openEventDetailsModal(ev) {
        let modal = document.getElementById('academicEventModal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = 'academicEventModal';
        document.body.appendChild(modal);

        const dateObj = new Date(ev.date + 'T00:00:00');
        const formattedDate = !isNaN(dateObj.getTime())
            ? dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
            : ev.date;

        modal.innerHTML = `
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#59443F]/40 backdrop-blur-xs font-['Inter',sans-serif] animate-fadeIn">
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/70 shadow-soft-xl max-w-md w-full overflow-hidden transform transition-all">
                    <!-- Modal Header -->
                    <div class="px-6 py-4 border-b border-[#E8C7C5]/40 flex items-center justify-between bg-[#F8EEEE]/60">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-regular fa-calendar-check text-sm"></i>
                            </div>
                            <div>
                                <span class="inline-flex items-center gap-1 text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 uppercase tracking-wider">
                                    ${this.escapeHtml(ev.type || 'General Event')}
                                </span>
                                <h3 class="font-heading font-extrabold text-sm text-[#59443F] tracking-tight mt-0.5">${this.escapeHtml(ev.event)}</h3>
                            </div>
                        </div>
                        <button type="button" class="ac-modal-close text-[#8B7168] hover:text-[#59443F] hover:bg-[#E8C7C5]/40 w-7 h-7 rounded-lg flex items-center justify-center transition cursor-pointer" aria-label="Close">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div class="p-6 space-y-4">
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/50 space-y-2">
                            <div class="flex items-center gap-2 text-xs text-[#59443F] font-bold">
                                <i class="fa-regular fa-calendar-days text-[#D6A6A6] w-4 text-center"></i>
                                <span>${formattedDate}</span>
                            </div>
                            ${ev.time ? `
                                <div class="flex items-center gap-2 text-xs text-[#8B7168] font-medium">
                                    <i class="fa-regular fa-clock text-[#D6A6A6] w-4 text-center"></i>
                                    <span>${this.escapeHtml(ev.time)}${ev.end_time ? ' – ' + this.escapeHtml(ev.end_time) : ''}</span>
                                </div>
                            ` : ''}
                        </div>

                        ${ev.note ? `
                            <div>
                                <h4 class="text-[10px] font-bold text-[#8B7168] uppercase tracking-wider mb-1.5">Event Details &amp; Notes</h4>
                                <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/40 border border-[#E8C7C5]/40 text-xs text-[#59443F] leading-relaxed">
                                    ${this.escapeHtml(ev.note)}
                                </div>
                            </div>
                        ` : ''}

                        <!-- Permission Notice Banner -->
                        <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#F8EEEE] border border-[#E8C7C5]/60 text-[11px] text-[#8B7168]">
                            <i class="fa-solid fa-shield-halved text-[#D6A6A6] text-xs"></i>
                            <span><strong>${this.readOnly ? 'Read-Only Mode' : 'Academic Schedule'}:</strong> Official seminary calendar schedule.</span>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="px-6 py-4 bg-[#F8EEEE]/40 border-t border-[#E8C7C5]/40 flex items-center justify-end">
                        <button type="button" class="ac-modal-close px-4 py-2 rounded-xl bg-[#59443F] text-white hover:bg-[#8B7168] text-xs font-bold shadow-soft transition cursor-pointer">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `;

        const closeBtnList = modal.querySelectorAll('.ac-modal-close');
        closeBtnList.forEach(btn => btn.addEventListener('click', () => modal.remove()));
        const backdrop = modal.querySelector('.fixed');
        if (backdrop) {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) modal.remove();
            });
        }
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    }

    formatDateKey(year, month, day) {
        const m = String(month).padStart(2, '0');
        const d = String(day).padStart(2, '0');
        return `${year}-${m}-${d}`;
    }

    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

if (typeof window !== 'undefined') {
    window.AcademicCalendar = AcademicCalendar;
}
