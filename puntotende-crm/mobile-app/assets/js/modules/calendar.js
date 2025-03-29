/**
 * PuntoTende CRM - Mobile Web App
 * Calendar Module
 */

export default class CalendarModule {
    constructor(app) {
        this.app = app;
        this.currentMonth = new Date();
        this.events = [];
    }
    
    // Inizializza la vista calendario
    async init() {
        this.setupMonthNavigation();
        this.renderCalendar();
        await this.loadEvents();
    }
    
    // Configura la navigazione dei mesi
    setupMonthNavigation() {
        const prevMonthBtn = document.getElementById('prev-month');
        const nextMonthBtn = document.getElementById('next-month');
        
        if (prevMonthBtn) {
            prevMonthBtn.addEventListener('click', () => {
                this.changeMonth(-1);
            });
        }
        
        if (nextMonthBtn) {
            nextMonthBtn.addEventListener('click', () => {
                this.changeMonth(1);
            });
        }
        
        // Aggiorna il titolo del mese
        this.updateMonthTitle();
    }
    
    // Cambia il mese visualizzato
    changeMonth(change) {
        this.currentMonth.setMonth(this.currentMonth.getMonth() + change);
        
        // Aggiorna il titolo del mese
        this.updateMonthTitle();
        
        // Renderizza nuovamente il calendario
        this.renderCalendar();
        
        // Ricarica gli eventi
        this.loadEvents();
    }
    
    // Aggiorna il titolo del mese
    updateMonthTitle() {
        const currentMonthEl = document.getElementById('current-month');
        if (!currentMonthEl) return;
        
        const options = { month: 'long', year: 'numeric' };
        currentMonthEl.textContent = this.currentMonth.toLocaleDateString('it-IT', options);
    }
    
    // Renderizza il calendario
    renderCalendar() {
        const calendarContainer = document.getElementById('calendar-container');
        if (!calendarContainer) return;
        
        const year = this.currentMonth.getFullYear();
        const month = this.currentMonth.getMonth();
        
        // Primo giorno del mese
        const firstDay = new Date(year, month, 1);
        
        // Ultimo giorno del mese
        const lastDay = new Date(year, month + 1, 0);
        
        // Giorno della settimana del primo giorno (0-6)
        let dayOfWeek = firstDay.getDay();
        dayOfWeek = dayOfWeek === 0 ? 6 : dayOfWeek - 1; // Converti da domenica-primo (0-6) a lunedì-primo (0-6)
        
        // Numero totale di giorni nel mese
        const daysInMonth = lastDay.getDate();
        
        // Genera il calendario
        let calendarHtml = `
            <div class="calendar">
                <div class="calendar-header">
                    <div class="calendar-day">Lun</div>
                    <div class="calendar-day">Mar</div>
                    <div class="calendar-day">Mer</div>
                    <div class="calendar-day">Gio</div>
                    <div class="calendar-day">Ven</div>
                    <div class="calendar-day">Sab</div>
                    <div class="calendar-day">Dom</div>
                </div>
                <div class="calendar-body">
        `;
        
        // Aggiungi celle vuote per i giorni precedenti al primo del mese
        for (let i = 0; i < dayOfWeek; i++) {
            calendarHtml += '<div class="calendar-date empty"></div>';
        }
        
        // Oggi
        const today = new Date();
        const isCurrentMonth = today.getFullYear() === year && today.getMonth() === month;
        
        // Aggiungi i giorni del mese
        for (let day = 1; day <= daysInMonth; day++) {
            const isToday = isCurrentMonth && today.getDate() === day;
            
            calendarHtml += `
                <div class="calendar-date ${isToday ? 'today' : ''}" data-date="${year}-${(month + 1).toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}">
                    <div class="calendar-date-number">${day}</div>
                    <div class="event-dots"></div>
                </div>
            `;
        }
        
        // Completa la riga con celle vuote
        const totalCells = dayOfWeek + daysInMonth;
        const remainingCells = 7 - (totalCells % 7);
        
        if (remainingCells < 7) {
            for (let i = 0; i < remainingCells; i++) {
                calendarHtml += '<div class="calendar-date empty"></div>';
            }
        }
        
        calendarHtml += '</div></div>';
        
        // Inserisci il calendario nel container
        calendarContainer.innerHTML = calendarHtml;
        
        // Aggiungi event listeners
        document.querySelectorAll('.calendar-date:not(.empty)').forEach(dateEl => {
            dateEl.addEventListener('click', () => {
                this.selectDate(dateEl.dataset.date);
            });
        });
    }
    
    // Carica gli eventi per il mese corrente
    async loadEvents() {
        try {
            // Calcola primo e ultimo giorno del mese
            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            
            const startDate = new Date(year, month, 1);
            const endDate = new Date(year, month + 1, 0);
            
            const params = new URLSearchParams({
                start: startDate.toISOString(),
                end: endDate.toISOString()
            });
            
            const response = await fetch(`${this.app.config.apiBase}/calendar/events?${params}`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento degli eventi');
            }
            
            const data = await response.json();
            this.events = data || [];
            
            // Aggiorna il calendario con gli eventi
            this.updateCalendarWithEvents();
            
            // Mostra gli eventi per la data selezionata o per oggi
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];
            
            // Cerca se c'è già una data selezionata
            const selectedDateEl = document.querySelector('.calendar-date.selected');
            const selectedDate = selectedDateEl ? selectedDateEl.dataset.date : todayStr;
            
            this.showEventsForDate(selectedDate);
            
        } catch (error) {
            console.error('Error loading events:', error);
            this.app.showError('Errore', 'Impossibile caricare gli eventi del calendario');
        }
    }
    
    // Aggiorna il calendario con indicatori eventi
    updateCalendarWithEvents() {
        // Raggruppa gli eventi per data
        const eventsByDate = {};
        
        this.events.forEach(event => {
            const eventDate = event.start.split('T')[0];
            
            if (!eventsByDate[eventDate]) {
                eventsByDate[eventDate] = [];
            }
            
            eventsByDate[eventDate].push(event);
        });
        
        // Aggiungi indicatori agli eventi
        Object.keys(eventsByDate).forEach(date => {
            const dateEl = document.querySelector(`.calendar-date[data-date="${date}"]`);
            if (!dateEl) return;
            
            const eventCount = eventsByDate[date].length;
            const dotsContainer = dateEl.querySelector('.event-dots');
            
            if (dotsContainer) {
                // Aggiungi fino a 3 puntini per gli eventi
                const dots = Math.min(eventCount, 3);
                let dotsHtml = '';
                
                for (let i = 0; i < dots; i++) {
                    dotsHtml += '<div class="event-dot"></div>';
                }
                
                dotsContainer.innerHTML = dotsHtml;
            }
            
            // Aggiungi classe per indicare che ci sono eventi
            dateEl.classList.add('has-events');
        });
    }
    
    // Seleziona una data
    selectDate(dateStr) {
        // Rimuovi la selezione precedente
        document.querySelectorAll('.calendar-date.selected').forEach(el => {
            el.classList.remove('selected');
        });
        
        // Aggiungi la classe selected alla nuova data
        const dateEl = document.querySelector(`.calendar-date[data-date="${dateStr}"]`);
        if (dateEl) {
            dateEl.classList.add('selected');
        }
        
        // Mostra gli eventi per questa data
        this.showEventsForDate(dateStr);
    }
    
    // Mostra gli eventi per una data specifica
    showEventsForDate(dateStr) {
        const eventsContainer = document.getElementById('events-list');
        const eventsDateEl = document.getElementById('events-date');
        
        if (!eventsContainer) return;
        
        // Formatta la data
        const date = new Date(dateStr);
        const formattedDate = date.toLocaleDateString('it-IT', { 
            weekday: 'long', 
            day: 'numeric', 
            month: 'long' 
        });
        
        // Aggiorna il titolo
        if (eventsDateEl) {
            eventsDateEl.textContent = `Eventi del ${formattedDate}`;
        }
        
        // Filtra gli eventi per la data selezionata
        const eventsForDate = this.events.filter(event => {
            return event.start.startsWith(dateStr);
        });
        
        // Visualizza gli eventi
        if (eventsForDate.length === 0) {
            eventsContainer.innerHTML = `<div class="empty-message">
                Nessun evento in questa data
            </div>`;
            return;
        }
        
        // Ordina gli eventi per ora
        eventsForDate.sort((a, b) => {
            return new Date(a.start) - new Date(b.start);
        });
        
        // Genera HTML
        let eventsHtml = '';
        
        eventsForDate.forEach(event => {
            // Formatta ora inizio
            let startTime = '';
            if (event.start.includes('T')) {
                startTime = new Date(event.start).toLocaleTimeString('it-IT', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
            
            eventsHtml += `
                <div class="event-item" data-id="${event.id}">
                    ${startTime ? `<div class="event-time">${startTime}</div>` : ''}
                    <div class="event-content">
                        <div class="event-title">${event.title}</div>
                        ${event.location ? `<div class="event-location">${event.location}</div>` : ''}
                    </div>
                    <div class="event-action">
                        <span class="material-icons">chev