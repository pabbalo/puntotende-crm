/**
 * PuntoTende CRM - Mobile Web App
 * Customers Module
 */

export default class CustomersModule {
    constructor(app) {
        this.app = app;
        this.customersData = [];
        this.currentPage = 1;
        this.searchTerm = '';
        this.hasMorePages = true;
    }
    
    // Inizializza la vista clienti
    async init() {
        this.setupListeners();
        await this.loadCustomers();
    }
    
    // Configura i listener degli eventi
    setupListeners() {
        const searchInput = document.getElementById('customer-search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchTerm = e.target.value;
                this.debounceSearch();
            });
        }
        
        const loadMoreBtn = document.getElementById('load-more-btn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => {
                this.loadMoreCustomers();
            });
        }
        
        // FAB per nuovo cliente
        const fab = document.getElementById('fab');
        if (fab) {
            fab.addEventListener('click', () => {
                this.showNewCustomerModal();
            });
        }
    }
    
    // Debounce per la ricerca
    debounceSearch() {
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }
        
        this.searchTimeout = setTimeout(() => {
            this.currentPage = 1;
            this.loadCustomers(true);
        }, 500);
    }
    
    // Carica la lista clienti
    async loadCustomers(reset = false) {
        const customersList = document.getElementById('customers-list');
        const loadMoreContainer = document.getElementById('load-more-container');
        
        if (!customersList) return;
        
        if (reset) {
            customersList.innerHTML = '<div class="loading">Ricerca clienti...</div>';
        } else if (this.currentPage === 1) {
            customersList.innerHTML = '<div class="loading">Caricamento clienti...</div>';
        }
        
        try {
            const params = new URLSearchParams({
                page: this.currentPage,
                per_page: 20
            });
            
            if (this.searchTerm) {
                params.append('search', this.searchTerm);
            }
            
            const response = await fetch(`${this.app.config.apiBase}/customers?${params}`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento dei clienti');
            }
            
            const data = await response.json();
            this.hasMorePages = data.has_more;
            
            // Aggiorna i dati
            if (reset || this.currentPage === 1) {
                this.customersData = data.customers || [];
            } else {
                this.customersData = [...this.customersData, ...data.customers || []];
            }
            
            // Visualizza o nascondi pulsante "Carica altro"
            if (loadMoreContainer) {
                loadMoreContainer.classList.toggle('hidden', !this.hasMorePages);
            }
            
            // Aggiorna la UI
            this.renderCustomersList();
            
        } catch (error) {
            console.error('Error loading customers:', error);
            customersList.innerHTML = `<div class="error-message">
                <span class="material-icons">error</span>
                <p>Errore nel caricamento dei clienti: ${error.message}</p>
            </div>`;
        }
    }
    
    // Carica altri clienti
    loadMoreCustomers() {
        if (this.hasMorePages) {
            this.currentPage++;
            this.loadCustomers();
        }
    }
    
    // Renderizza la lista clienti
    renderCustomersList() {
        const customersList = document.getElementById('customers-list');
        
        if (!customersList) return;
        
        if (this.customersData.length === 0) {
            customersList.innerHTML = `<div class="empty-state">
                <span class="material-icons">people</span>
                <p>Nessun cliente trovato</p>
            </div>`;
            return;
        }
        
        if (this.currentPage === 1) {
            customersList.innerHTML = '';
        }
        
        // Crea gli elementi della lista
        const fragment = document.createDocumentFragment();
        
        this.customersData.forEach(customer => {
            const item = document.createElement('div');
            item.className = 'list-item';
            item.dataset.id = customer.id;
            
            item.innerHTML = `
                <div class="list-item-icon">
                    <span class="material-icons">person</span>
                </div>
                <div class="list-item-content">
                    <div class="list-item-title">${customer.name}</div>
                    <div class="list-item-subtitle">
                        ${customer.phone || ''}
                        ${customer.phone && customer.email ? ' • ' : ''}
                        ${customer.email || ''}
                    </div>
                </div>
                <div class="list-item-action">
                    <span class="material-icons">chevron_right</span>
                </div>
            `;
            
            // Aggiungi event listener
            item.addEventListener('click', () => {
                this.app.showCustomerDetail(customer.id);
            });
            
            fragment.appendChild(item);
        });
        
        // Aggiungi alla lista esistente o sostituisci
        if (this.currentPage === 1) {
            customersList.innerHTML = '';
        }
        
        customersList.appendChild(fragment);
    }
    
    // Mostra modal per nuovo cliente
    showNewCustomerModal() {
        // Implementazione della modal per aggiungere un nuovo cliente
        // Qui dovremmo usare una funzione dall'app principale per mostrare una modal
        this.app.showModal('new-customer-modal', {
            onSave: (customerData) => this.createCustomer(customerData)
        });
    }
    
    // Crea un nuovo cliente
    async createCustomer(customerData) {
        try {
            const response = await fetch(`${this.app.config.apiBase}/customers`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.app.wpNonce
                },
                body: JSON.stringify(customerData),
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nella creazione del cliente');
            }
            
            const data = await response.json();
            
            // Aggiorna la lista clienti
            this.currentPage = 1;
            await this.loadCustomers(true);
            
            // Chiudi la modal
            this.app.closeModal();
            
            // Notifica
            this.app.showNotification('Cliente aggiunto con successo');
            
            return data;
        } catch (error) {
            console.error('Error creating customer:', error);
            this.app.showError('Errore nella creazione del cliente', error.message);
        }
    }
}