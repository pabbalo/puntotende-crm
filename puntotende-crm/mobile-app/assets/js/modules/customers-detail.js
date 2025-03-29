/**
 * PuntoTende CRM - Mobile Web App
 * Customer Detail Module
 */

export default class CustomerDetailModule {
    constructor(app) {
        this.app = app;
        this.customerId = null;
        this.customer = null;
    }
    
    // Inizializza la vista dettaglio cliente
    async init(customerId) {
        this.customerId = customerId;
        
        // Configura back button
        const backBtn = document.querySelector('.back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', () => {
                this.app.loadView('customers');
            });
        }
        
        // Configura pulsante nuovo appuntamento
        const newAppointmentBtn = document.getElementById('new-appointment-btn');
        if (newAppointmentBtn) {
            newAppointmentBtn.addEventListener('click', () => {
                this.showNewAppointmentForm();
            });
        }
        
        // Configura pulsante modifica cliente
        const editCustomerBtn = document.getElementById('edit-customer-btn');
        if (editCustomerBtn) {
            editCustomerBtn.addEventListener('click', () => {
                this.showEditCustomerForm();
            });
        }
        
        // Carica i dati del cliente
        await this.loadCustomerData();
    }
    
    // Carica i dati del cliente
    async loadCustomerData() {
        const customerInfoEl = document.getElementById('customer-info');
        const customerAppointmentsEl = document.getElementById('customer-appointments');
        
        try {
            customerInfoEl.innerHTML = '<div class="loading">Caricamento dati cliente...</div>';
            customerAppointmentsEl.innerHTML = '<div class="loading">Caricamento appuntamenti...</div>';
            
            const response = await fetch(`${this.app.config.apiBase}/customers/${this.customerId}`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento dei dati cliente');
            }
            
            const data = await response.json();
            this.customer = data.customer;
            
            // Aggiorna i dati del cliente nell'interfaccia
            this.updateCustomerUI();
            
            // Aggiorna gli appuntamenti
            this.updateAppointmentsUI(data.appointments);
            
        } catch (error) {
            console.error('Error loading customer data:', error);
            customerInfoEl.innerHTML = `
                <div class="error-message">
                    <p>Errore nel caricamento dei dati cliente</p>
                    <button class="btn-text" id="retry-customer">Riprova</button>
                </div>
            `;
            document.getElementById('retry-customer')?.addEventListener('click', () => {
                this.loadCustomerData();
            });
        }
    }
    
    // Aggiorna l'interfaccia con i dati del cliente
    updateCustomerUI() {
        document.getElementById('customer-name').textContent = this.customer.name;
        
        const phoneEl = document.getElementById('customer-phone');
        if (this.customer.phone) {
            phoneEl.href = `tel:${this.customer.phone}`;
            phoneEl.querySelector('.contact-value').textContent = this.customer.phone;
            phoneEl.classList.remove('hidden');
        } else {
            phoneEl.classList.add('hidden');
        }
        
        const emailEl = document.getElementById('customer-email');
        if (this.customer.email) {
            emailEl.href = `mailto:${this.customer.email}`;
            emailEl.querySelector('.contact-value').textContent = this.customer.email;
            emailEl.classList.remove('hidden');
        } else {
            emailEl.classList.add('hidden');
        }
        
        document.getElementById('customer-address').textContent = this.customer.address || 'Non specificato';
        document.getElementById('customer-notes').textContent = this.customer.notes || 'Nessuna nota';
    }
    
    // Aggiorna l'interfaccia con gli appuntamenti del cliente
    updateAppointmentsUI(appointments) {
        const appointmentsEl = document.getElementById('customer-appointments');
        
        if (!appointments || appointments.length === 0) {
            appointmentsEl.innerHTML = '<p class="empty-message">Nessun appuntamento per questo cliente</p>';
            return;
        }
        
        appointmentsEl.innerHTML = appointments.map(appt => `
            <div class="appointment-item" data-id="${appt.id}">
                <div class="appointment-date">${this.app.formatDate(appt.date)}</div>
                <div class="appointment-title">${appt.title}</div>
                <div class="appointment-status ${appt.status === 'completed' ? 'completed' : 'pending'}">
                    ${appt.status === 'completed' ? 'Completato' : 'In programma'}
                </div>
            </div>
        `).join('');
        
        // Aggiungi event listeners
        document.querySelectorAll('.appointment-item').forEach(item => {
            item.addEventListener('click', () => {
                this.app.loadAppointmentDetail(item.dataset.id);
            });
        });
    }
    
    // Mostra form per nuovo appuntamento
    showNewAppointmentForm() {
        // Prepopola il form con il cliente selezionato
        const appointmentCustomerSelect = document.getElementById('appointment-customer');
        if (appointmentCustomerSelect) {
            appointmentCustomerSelect.value = this.customerId;
            appointmentCustomerSelect.disabled = true; // Blocca la modifica
        }
        
        this.app.showModal('new-appointment-modal');
    }
    
    // Mostra form per modificare cliente
    showEditCustomerForm() {
        // Implementare la logica per editing del cliente
        // Prepopolare il modal con i dati attuali del cliente
        this.app.showModal('edit-customer-modal', this.customer);
    }
}