/**
 * Modern Mini Calendar Component for EnformaSys
 * Standardized across Cashier, Staff, and Secondary Dashboards.
 * Visually and structurally unified to match the Seminarian (Student) Calendar design:
 * - Pure white floating card with rounded-3xl, border-[#E8C7C5]/50, p-5 sm:p-6, shadow-soft
 * - Single-row minimalist header with font-heading font-extrabold text-[#59443F] and rounded-lg nav buttons
 * - 7-Column single-letter weekday header (S, M, T, W, T, F, S) in text-[#8B7168]
 * - Compact h-9 rounded-xl day cells with #E8C7C5 today pill, #F8EEEE hover/selected, and #D6A6A6 dots
 * - Soft #F8EEEE/60 rounded-xl events drawer with read-only badge and timetable link
 */
class ModernMiniCalendar {
    constructor(containerId, options = {}) {
        this.container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
        if (!this.container) return;

        this.baseUrl = options.baseUrl || 'ajax.php';
        this.readOnly = options.readOnly !== undefined ? Boolean(options.readOnly) : true;
        this.canAdd = options.canAdd !== undefined ? Boolean(options.canAdd) : false;
        this.timetableUrl = options.timetableUrl || 'timetable.php';

        const now = new Date();
        this.currentYear = now.getFullYear();
        this.currentMonth = now.getMonth(); // 0-indexed (0 = Jan, 9 = Oct)
        this.todayDate = now.getDate();
        this.todayMonth = now.getMonth();
        this.todayYear = now.getFullYear();
        this.todayStr = `${this.todayYear}-${String(this.todayMonth + 1).padStart(2, '0')}-${String(this.todayDate).padStart(2, '0')}`;
        this.selectedDateStr = this.todayStr;
        this.currentFilter = 'All';
        this.events = {};

        this.init();
    }

    init() {
        this.renderStructure();
        this.loadEvents();
    }

    renderStructure() {
        this.container.innerHTML = `
            <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 p-5 sm:p-6 shadow-soft font-['Inter',sans-serif]">
                <!-- Header: Month Title & Subtle Nav Chevrons (Matches Student Calendar) -->
                <div class="flex items-center justify-between mb-3 gap-2">
                    <h4 class="mc-title font-heading font-extrabold text-sm text-[#59443F] tracking-tight truncate"></h4>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <a href="${this.timetableUrl}" class="mc-full-timetable-btn inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold transition border border-[#E8C7C5]/60 shadow-2xs" title="Open Full View Timetable">
                            <i class="fa-solid fa-calendar-days text-[10px] text-[#D6A6A6]"></i>
                            <span class="hidden sm:inline">Full View Timetable</span>
                        </a>
                        <button type="button" class="mc-prev-btn w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Previous Month">
                            <i class="fa-solid fa-chevron-left text-[10px]"></i>
                        </button>
                        <button type="button" class="mc-next-btn w-7 h-7 rounded-lg bg-[#F8EEEE] hover:bg-[#E8C7C5] text-[#59443F] flex items-center justify-center text-xs transition cursor-pointer" aria-label="Next Month">
                            <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </button>
                    </div>
                </div>

                <!-- Category Filter Pills (Soft theme colors) -->
                <div class="flex items-center gap-1 overflow-x-auto text-[10px] font-bold scrollbar-none mb-3 pb-2.5 border-b border-[#E8C7C5]/30">
                    <button type="button" class="mc-filter-pill px-2.5 py-0.5 rounded-full transition active bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 cursor-pointer shadow-2xs" data-filter="All">All</button>
                    <button type="button" class="mc-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Academic">Academic</button>
                    <button type="button" class="mc-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Holidays">Holidays</button>
                    <button type="button" class="mc-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 cursor-pointer" data-filter="Events">Events</button>
                </div>

                <!-- 7-Column Day-of-Week Headers (S, M, T, W, T, F, S) -->
                <div class="grid grid-cols-7 gap-1 text-center mb-2">
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">M</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">W</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">T</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">F</span>
                    <span class="text-[10px] font-bold text-[#8B7168] py-1">S</span>
                </div>

                <!-- 7-Column Dates Grid -->
                <div class="mc-grid grid grid-cols-7 gap-1 text-center text-xs"></div>

                <!-- Read-Only Selected Day Event Details Drawer -->
                <div class="mc-details mt-4 pt-3 border-t border-[#E8C7C5]/40 text-xs"></div>
            </div>
        `;

        this.prevBtn = this.container.querySelector('.mc-prev-btn');
        this.nextBtn = this.container.querySelector('.mc-next-btn');
        this.titleEl = this.container.querySelector('.mc-title');
        this.gridEl = this.container.querySelector('.mc-grid');
        this.detailsEl = this.container.querySelector('.mc-details');

        this.prevBtn.addEventListener('click', () => this.changeMonth(-1));
        this.nextBtn.addEventListener('click', () => this.changeMonth(1));

        this.container.querySelectorAll('.mc-filter-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                const filter = btn.getAttribute('data-filter');
                this.setFilter(filter);
            });
        });
    }

    setFilter(filter) {
        this.currentFilter = filter;
        this.container.querySelectorAll('.mc-filter-pill').forEach(btn => {
            const f = btn.getAttribute('data-filter');
            if (f === filter) {
                btn.className = 'mc-filter-pill px-2.5 py-0.5 rounded-full transition active bg-[#E8C7C5] text-[#59443F] border border-[#D6A6A6]/60 font-bold cursor-pointer shadow-2xs';
            } else {
                btn.className = 'mc-filter-pill px-2.5 py-0.5 rounded-full transition bg-[#F8EEEE] text-[#8B7168] hover:bg-[#E8C7C5] hover:text-[#59443F] border border-[#E8C7C5]/40 font-bold cursor-pointer';
            }
        });
        this.renderGrid();
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

    async loadEvents() {
        const queryMonth = this.currentMonth + 1;
        try {
            const separator = this.baseUrl.includes('?') ? '&' : '?';
            const url = `${this.baseUrl}${separator}action=get_events_for_month&year=${this.currentYear}&month=${queryMonth}`;
            const res = await fetch(url);
            const data = await res.json();
            this.events = {};
            if (data && data.events && Array.isArray(data.events)) {
                data.events.forEach(ev => {
                    if (!this.events[ev.date]) this.events[ev.date] = [];
                    this.events[ev.date].push(ev);
                });
            }
        } catch (e) {
            this.events = {};
        }
        this.renderGrid();
        this.renderDetails(this.selectedDateStr);
    }

    changeMonth(delta) {
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

    renderGrid() {
        const monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        this.titleEl.textContent = `${monthNames[this.currentMonth]} ${this.currentYear}`;

        const firstDayIndex = new Date(this.currentYear, this.currentMonth, 1).getDay();
        const totalDays = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
        const prevMonthTotalDays = new Date(this.currentYear, this.currentMonth, 0).getDate();

        let cellsHtml = '';

        // Previous month trailing padding days
        for (let i = firstDayIndex - 1; i >= 0; i--) {
            const d = prevMonthTotalDays - i;
            cellsHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${d}</div>`;
        }

        // Current month active days
        for (let d = 1; d <= totalDays; d++) {
            const dateStr = `${this.currentYear}-${String(this.currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isToday = (d === this.todayDate && this.currentMonth === this.todayMonth && this.currentYear === this.todayYear);
            const isSelected = dateStr === this.selectedDateStr;
            const dayEvents = this.getFilteredEventsForDate(dateStr);
            const hasEvents = dayEvents.length > 0;

            let cellClasses = "mc-day-cell h-9 flex flex-col items-center justify-center rounded-xl cursor-pointer transition relative select-none ";
            if (isToday) {
                cellClasses += "bg-[#E8C7C5] text-[#59443F] font-black shadow-xs ring-1 ring-[#D6A6A6] ";
            } else if (isSelected) {
                cellClasses += "bg-[#F8EEEE] text-[#59443F] font-bold border border-[#E8C7C5] ";
            } else {
                cellClasses += "text-[#59443F] hover:bg-[#F8EEEE] font-semibold ";
            }

            // Indicator dots matching Student Calendar
            let dotsHtml = '';
            if (hasEvents) {
                dotsHtml = dayEvents.slice(0, 3).map(() => 
                    `<span class="w-1 h-1 rounded-full ${isToday ? 'bg-[#59443F]' : 'bg-[#D6A6A6]'} inline-block"></span>`
                ).join('');
            }

            const hoverTooltip = hasEvents 
                ? dayEvents.map(e => e.event + (e.time ? ' (' + e.time + ')' : '')).join(', ') 
                : dateStr;

            cellsHtml += `
                <div class="${cellClasses}" data-date="${dateStr}" title="${this.escapeHtml(hoverTooltip)}">
                    <span class="text-xs leading-none">${d}</span>
                    <div class="h-1.5 flex items-center justify-center gap-0.5 mt-0.5">${dotsHtml}</div>
                </div>
            `;
        }

        // Next month trailing days to complete 7-col row
        const totalRendered = firstDayIndex + totalDays;
        const remaining = (7 - (totalRendered % 7)) % 7;
        for (let i = 1; i <= remaining; i++) {
            cellsHtml += `<div class="h-9 flex flex-col items-center justify-center text-[#8B7168]/30 font-normal rounded-xl select-none text-[11px]">${i}</div>`;
        }

        this.gridEl.innerHTML = cellsHtml;

        // Attach click handlers
        this.gridEl.querySelectorAll('.mc-day-cell').forEach(cell => {
            cell.addEventListener('click', () => {
                const clickedDate = cell.getAttribute('data-date');
                this.selectedDateStr = clickedDate;
                this.renderGrid();
                this.renderDetails(clickedDate);
            });
        });
    }

    renderDetails(dateStr) {
        if (!this.detailsEl) return;
        const dayEvents = this.getFilteredEventsForDate(dateStr);
        const dateObj = new Date(dateStr + 'T00:00:00');
        const formattedDate = !isNaN(dateObj.getTime())
            ? dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
            : dateStr;

        if (dayEvents.length === 0) {
            this.detailsEl.innerHTML = `
                <div class="flex items-center justify-between mb-2">
                    <span class="font-bold text-[#59443F] text-[11px] uppercase tracking-wider">${formattedDate} Events</span>
                    <span class="text-[9px] font-semibold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded border border-[#E8C7C5]/40 flex items-center gap-1">
                        ${this.readOnly ? '<i class="fa-solid fa-lock text-[8px] text-[#8B7168]"></i> Read-Only' : '0 Events'}
                    </span>
                </div>
                <div class="p-2.5 rounded-xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/30 text-center text-[#8B7168] text-[11px] italic">
                    No scheduled timetable items for this day.
                </div>
            `;
            return;
        }

        let eventsHtml = dayEvents.map(ev => {
            let timeStr = ev.time ? ev.time.substring(0, 5) : 'All Day';
            return `
                <div class="p-2.5 rounded-xl bg-[#F8EEEE]/60 border border-[#E8C7C5]/40 hover:bg-[#F8EEEE] transition">
                    <div class="flex items-center justify-between gap-1 mb-1">
                        <span class="font-bold text-xs text-[#59443F] truncate">${this.escapeHtml(ev.event)}</span>
                        ${ev.type ? `
                            <span class="text-[9px] font-black px-1.5 py-0.2 rounded bg-white text-[#59443F] border border-[#E8C7C5]/50 shrink-0">
                                ${this.escapeHtml(ev.type)}
                            </span>
                        ` : ''}
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

        const headerActions = (!this.readOnly && this.canAdd)
            ? `<a href="${this.timetableUrl}?prefill_date=${dateStr}" class="text-[10px] font-bold text-[#59443F] hover:text-[#8B7168]"><i class="fa-solid fa-plus text-[9px] text-[#D6A6A6] mr-1"></i>Add</a>`
            : `<span class="text-[9px] font-semibold text-[#8B7168] bg-[#F8EEEE] px-2 py-0.5 rounded border border-[#E8C7C5]/40 flex items-center gap-1"><i class="fa-solid fa-calendar-check text-[8px] text-[#D6A6A6]"></i> ${dayEvents.length} Item${dayEvents.length > 1 ? 's' : ''}</span>`;

        this.detailsEl.innerHTML = `
            <div class="flex items-center justify-between mb-2">
                <span class="font-bold text-[#59443F] text-[11px] uppercase tracking-wider">${formattedDate} Events</span>
                ${headerActions}
            </div>
            <div class="max-h-48 overflow-y-auto space-y-1.5 pr-1">
                ${eventsHtml}
            </div>
        `;
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
    window.ModernMiniCalendar = ModernMiniCalendar;
}
