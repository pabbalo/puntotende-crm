/**
 * PuntoTende CRM - Mobile Web App
 * Appointments Module
 */

export default class AppointmentsModule {
    constructor(app) {
        this.app = app;
        this.appointments = [];
        this.currentDate = new Date();
    }
    
    // Inizializza la vista appuntamenti
    async init() {
        this.setupDateNavigation();
        await this.loadAppointments();
        
        // Configura FAB per nuovo appuntamento
        const fab = document.getElementById('fab');
        if (fab) {
            fab.addEventListener('click', () => {
                this.showNewAppointmentModal();
            });
        }
    }
    
    // Configura la navigazione delle date
    setupDateNavigation() {
        const prevDateBtn = document.getElementById('prev-date');
        const nextDateBtn = document.getElementById('next-date');
        const currentDateEl = document.getElementById('current-date');
        
        if (prevDateBtn) {
            prevDateBtn.addEventListener('click', () => {
                this.changeDate(-1);
            });
        }
        
        if (nextDateBtn) {
            nextDateBtn.addEventListener('click', () => {
                this.changeDate(1);
            });
        }
        
        // Imposta la data corrente
        this.updateDateDisplay();
    }
    
    // Cambia la data corrente
    changeDate(days) {
        this.currentDate = new Date(this.currentDate);
        this.currentDate.setDate(this.currentDate.getDate() + days);
        
        // Aggiorna il display della data
        this.updateDateDisplay();
        
        // Ricarica gli appuntamenti
        this.loadAppointments();
    }
    
    // Aggiorna il display della data
    updateDateDisplay() {
        const currentDateEl = document.getElementById('current-date');
        if (!currentDateEl) return;
        
        // Verifica se è oggi
        const today = new Date();
        const isToday = this.currentDate.toDateString() === today.toDateString();
        
        // Formatta la data
        const options = { weekday: 'long', day: 'numeric', month: 'long' };
        let dateText = this.currentDate.toLocaleDateString('it-IT', options);
        
        if (isToday) {
            dateText = `Oggi, ${dateText}`;
        }
        
        currentDateEl.textContent = dateText;
    }
    
    // Carica gli appuntamenti per la data corrente
    async loadAppointments() {
        const appointmentsList = document.getElementById('appointments-list');
        const noAppointments = document.getElementById('no-appointments');
        
        if (!appointmentsList) return;
        
        appointmentsList.innerHTML = '<div class="loading">Caricamento appuntamenti...</div>';
        
        try {
            // Formatta la data per l'API
            const dateStr = this.formatDateForApi(this.currentDate);
            
            const response = await fetch(`${this.app.config.apiBase}/appointments?date=${dateStr}`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento degli appuntamenti');
            }
            
            const data = await response.json();
            this.appointments = data.appointments || [];
            
            // Aggiorna la UI
            this.renderAppointments();
            
            // Mostra/nascondi stato vuoto
            if (noAppointments) {
                if (this.appointments.length === 0) {
                    noAppointments.classList.remove('hidden');
                } else {
                    noAppointments.classList.add('hidden');
                }
            }
            
        } catch (error) {
            console.error('Error loading appointments:', error);
            
            if (appointmentsList) {
                appointmentsList.innerHTML = `<div class="error-message">
                    <span class="material-icons">error</span>
                    <p>Errore nel caricamento degli appuntamenti: ${error.message}</p>
                </div>`;
            }
            
            if (noAppointments) {
                noAppointments.classList.add('hidden');
            }
        }
    }
    
    // Formatta la data per l'API
    formatDateForApi(date) {
        return date.toISOString().split('T')[0];
    }
    
    // Renderizza gli appuntamenti
    renderAppointments() {
        const appointmentsList = document.getElementById('appointments-list');
        if (!appointmentsList) return;
        
        if (this.appointments.length === 0) {
            appointmentsList.innerHTML = '';
            return;
        }
        
        // Ordina gli appuntamenti per ora
        const sortedAppointments = [...this.appointments].sort((a, b) => {
            return new Date(a.start_time) - new Date(b.start_time);
        });
        
        // Crea elementi HTML
        let html = '';
        
        sortedAppointments.forEach(appointment => {
            const startTime = new Date(appointment.start_time);
            const formattedTime = startTime.toLocaleTimeString('it-IT', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            html += `
                <div class="appointment-card" data-id="${appointment.id}">
                    <div class="appointment-time">${formattedTime}</div>
                    <div class="appointment-content">
                        <div class="appointment-title">${appointment.title}</div>
                        <div class="appointment-customer">
                            <span class="material-icons">person</span>
                            ${appointment.customer_name || 'Cliente non specificato'}
                        </div>
                        ${appointment.location ? `
                            <div class="appointment-location">
                                <span class="material-icons">place</span>
                                ${appointment.location}
                            </div>
                        ` : ''}
                    </div>
                    <div class="appointment-actions">
                        <button class="icon-btn">
                            <span class="material-icons">more_vert</span>
                        </button>
                    </div>
                </div>
            `;
        });
        
        appointmentsList.innerHTML = html;
        
        // Aggiungi event listeners
        document.querySelectorAll('.appointment-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = card.dataset.id;
                this.viewAppointmentDetails(id);
            });
        });
    }
    
    // Mostra modal per nuovo appuntamento
    showNewAppointmentModal() {
        const modal = document.getElementById('new-appointment-modal');
        if (!modal) return;
        
        // Prepara il form
        const form = document.getElementById('new-appointment-form');
        if (form) form.reset();
        
        // Imposta la data di default (data corrente)
        const dateInput = document.getElementById('appointment-date');
        if (dateInput) {
            const now = new Date();
            now.setMinutes(Math.ceil(now.getMinutes() / 15) * 15); // Arrotonda ai prossimi 15 minuti
            const localDateTime = now.toISOString().slice(0, 16);
            dateInput.value = localDateTime;
        }
        
        // Carica i clienti per il dropdown
        this.loadCustomersForDropdown();
        
        // Mostra la modal
        modal.classList.remove('hidden');
        
        // Configura i pulsanti
        const saveBtn = modal.querySelector('.save-btn');
        const cancelBtn = modal.querySelector('.cancel-btn');
        const closeBtn = modal.querySelector('.close-modal');
        
        if (saveBtn) {
            saveBtn.onclick = () => this.saveAppointment();
        }
        
        if (cancelBtn) {
            cancelBtn.onclick = () => this.closeModal(modal);
        }
        
        if (closeBtn) {
            closeBtn.onclick = () => this.closeModal(modal);
        }
    }
    
    // Carica i clienti per il dropdown
    async loadCustomersForDropdown() {
        const customerSelect = document.getElementById('appointment-customer');
        if (!customerSelect) return;
        
        try {
            // Mostra loading
            customerSelect.innerHTML = '<option value="">Caricamento...</option>';
            
            const response = await fetch(`${this.app.config.apiBase}/customers?per_page=100`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento dei clienti');
            }
            
            const data = await response.json();
            const customers = data.customers || [];
            
            // Aggiorna il dropdown
            customerSelect.innerHTML = '<option value="">Seleziona cliente...</option>';
            
            customers.forEach(customer => {
                const option = document.createElement('option');
                option.value = customer.id;
                option.textContent = customer.name;
                customerSelect.appendChild(option);
            });
            
        } catch (error) {
            console.error('Error loading customers for dropdown:', error);
            customerSelect.innerHTML = '<option value="">Errore nel caricamento</option>';
        }
    }
    
    // Salva un nuovo appuntamento
    async saveAppointment() {
        const form = document.getElementById('new-appointment-form');
        if (!form) return;
        
        // Validazione form
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        // Raccolta dati
        const appointmentData = {
            title: document.getElementById('appointment-title').value,
            date: document.getElementById('appointment-date').value,
            duration: document.getElementById('appointment-duration').value,
            customer_id: document.getElementById('appointment-customer').value,
            notes: document.getElementById('appointment-notes').value
        };
        
        try {
            const response = await fetch(`${this.app.config.apiBase}/appointments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.app.wpNonce
                },
                body: JSON.stringify(appointmentData),
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || 'Errore nella creazione dell\'appuntamento');
            }
            
            const result = await response.json();
            
            // Chiudi la modal
            this.closeModal(document.getElementById('new-appointment-modal'));
            
            // Mostra notifica
            this.app.showNotification('Appuntamento creato con successo');
            
            // Ricarica gli appuntamenti
            this.loadAppointments();
            
        } catch (error) {
            console.error('Error saving appointment:', error);
            this.app.showError('Errore', error.message);
        }
    }
    
    // Chiudi modal
    closeModal(modal) {
        if (modal) modal.classList.add('hidden');
    }
    
    // Visualizza dettagli appuntamento
    viewAppointmentDetails(id) {
        const appointment = this.appointments.find(a => a.id == id);
        if (!appointment) return;
        
        // Qui implementerai la visualizzazione dei dettagli
        // Per ora, mostriamo un alert
        alert(`Dettagli appuntamento: ${appointment.title}`);
    }
}