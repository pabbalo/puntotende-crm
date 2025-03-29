/**
 * PuntoTende CRM - Mobile Web App
 * Main Application JavaScript
 */

// Configurazione
const config = {
    apiBase: '/wp-json/ptcrm/v1',
    loginUrl: '/wp-login.php',
    appName: 'PuntoTende CRM'
};

// Controller principale dell'app
class App {
    constructor() {
        this.currentView = 'dashboard';
        this.userData = null;
        this.viewCache = {};
        this.setupEventListeners();
    }

    // Inizializza l'app
    async init() {
        console.log('Initializing PuntoTende CRM mobile app...');
        
        try {
            // Verifica lo stato di autenticazione
            const authStatus = await this.checkAuth();
            
            // Nascondi la splash screen
            document.getElementById('splash-screen').classList.add('hidden');
            
            if (authStatus.logged_in) {
                // Utente autenticato, mostra l'app
                this.userData = authStatus.user;
                this.showApp();
                this.loadView(this.currentView);
            } else {
                // Utente non autenticato, mostra login
                this.showLogin();
            }
        } catch (error) {
            console.error('Initialization error:', error);
            this.showError('Errore di inizializzazione', error.message);
        }
    }

    // Configura i listener degli eventi
    setupEventListeners() {
        // Login
        const loginBtn = document.getElementById('login-btn');
        if (loginBtn) {
            loginBtn.addEventListener('click', () => {
                window.location.href = `${config.loginUrl}?redirect_to=${encodeURIComponent(window.location.href)}`;
            });
        }
        
        // Menu laterale
        const menuToggle = document.getElementById('menu-toggle');
        const closeMenuBtn = document.getElementById('close-menu-btn');
        const overlay = document.getElementById('overlay');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => this.toggleSideMenu(true));
        }
        
        if (closeMenuBtn) {
            closeMenuBtn.addEventListener('click', () => this.toggleSideMenu(false));
        }
        
        if (overlay) {
            overlay.addEventListener('click', () => this.toggleSideMenu(false));
        }
        
        // Navigazione
        const menuItems = document.querySelectorAll('#side-menu nav ul li');
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                const view = item.dataset.view;
                this.loadView(view);
                this.toggleSideMenu(false);
            });
        });
        
        // Bottom Nav
        const bottomNavBtns = document.querySelectorAll('#bottom-nav button');
        bottomNavBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.dataset.view;
                this.loadView(view);
            });
        });
        
        // Logout
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => this.logout());
        }
        
        // Refresh button
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refreshCurrentView());
        }
    }

    // Verifica lo stato di autenticazione
    async checkAuth() {
        try {
            const response = await fetch(`${config.apiBase}/auth/status`, {
                credentials: 'same-origin' // Importante per inviare i cookie
            });
            
            if (!response.ok) {
                throw new Error('Errore nella verifica dell\'autenticazione');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Auth check error:', error);
            return { logged_in: false };
        }
    }

    // Mostra l'interfaccia di login
    showLogin() {
        document.getElementById('login-container').classList.remove('hidden');
        document.getElementById('app-container').classList.add('hidden');
    }

    // Mostra l'interfaccia principale dell'app
    showApp() {
        document.getElementById('login-container').classList.add('hidden');
        document.getElementById('app-container').classList.remove('hidden');
        
        // Aggiorna le info utente
        if (this.userData) {
            document.getElementById('user-avatar').src = this.userData.avatar || 'assets/img/default-avatar.png';
            document.getElementById('menu-user-avatar').src = this.userData.avatar || 'assets/img/default-avatar.png';
            document.getElementById('user-name').textContent = this.userData.name;
        }
    }

    // Apri/chiudi menu laterale
    toggleSideMenu(open) {
        const sideMenu = document.getElementById('side-menu');
        const overlay = document.getElementById('overlay');
        
        if (open) {
            sideMenu.classList.add('open');
            overlay.classList.add('visible');
            document.body.classList.add('menu-open');
        } else {
            sideMenu.classList.remove('open');
            overlay.classList.remove('visible');
            document.body.classList.remove('menu-open');
        }
    }

    // Carica una vista
    async loadView(viewName) {
        // Aggiorna il titolo della pagina
        document.getElementById('page-title').textContent = this.getViewTitle(viewName);
        
        // Aggiorna navigation attiva
        this.updateActiveNavigation(viewName);
        
        // Visualizza/nascondi FAB in base alla vista
        this.toggleFAB(viewName);
        
        try {
            // Ottieni il template
            const template = document.getElementById(`${viewName}-view`);
            if (!template) {
                throw new Error(`Template per la vista "${viewName}" non trovato`);
            }
            
            // Clona il template
            const viewContent = template.content.cloneNode(true);
            
            // Pulisci il container e inserisci la nuova vista
            const viewContainer = document.getElementById('view-container');
            viewContainer.innerHTML = '';
            viewContainer.appendChild(viewContent);
            
            // Inizializza la vista
            await this.initView(viewName);
            
            // Aggiorna la vista corrente
            this.currentView = viewName;
        } catch (error) {
            console.error(`Error loading view ${viewName}:`, error);
            this.showError(`Errore nel caricamento della vista ${viewName}`, error.message);
        }
    }

    // Inizializza una vista specifica
    async initView(viewName) {
        switch (viewName) {
            case 'dashboard':
                await this.initDashboardView();
                break;
            case 'customers':
                await this.initCustomersView();
                break;
            case 'appointments':
                await this.initAppointmentsView();
                break;
            case 'calendar':
                await this.initCalendarView();
                break;
            default:
                console.log(`No initialization needed for view: ${viewName}`);
        }
    }

    // Inizializza la vista dashboard
    async initDashboardView() {
        try {
            const response = await fetch(`${config.apiBase}/dashboard`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento dei dati dashboard');
            }
            
            const data = await response.json();
            
            // Aggiorna statistiche
            document.getElementById('stats-appointments').textContent = data.stats.appointments_count || 0;
            document.getElementById('stats-customers').textContent = data.stats.customers_count || 0;
            document.getElementById('stats-today').textContent = data.stats.today_appointments || 0;
            document.getElementById('stats-month').textContent = data.stats.month_appointments || 0;
            
            // Aggiorna appuntamenti
            const upcomingAppointmentsEl = document.getElementById('upcoming-appointments');
            if (data.upcoming_appointments && data.upcoming_appointments.length > 0) {
                upcomingAppointmentsEl.innerHTML = data.upcoming_appointments.map(appt => `
                    <div class="list-item">
                        <div class="list-item-icon">
                            <span class="material-icons">event</span>
                        </div>
                        <div class="list-item-content">
                            <div class="list-item-title">${appt.title}</div>
                            <div class="list-item-subtitle">${this.formatDate(appt.date)}</div>
                        </div>
                        <div class="list-item-action">
                            <span class="material-icons">chevron_right</span>
                        </div>
                    </div>
                `).join('');
            } else {
                upcomingAppointmentsEl.innerHTML = '<p class="empty-message">Nessun appuntamento programmato</p>';
            }
            
            // Aggiorna clienti
            const recentCustomersEl = document.getElementById('recent-customers');
            if (data.recent_customers && data.recent_customers.length > 0) {
                recentCustomersEl.innerHTML = data.recent_customers.map(customer => `
                    <div class="list-item" data-id="${customer.id}">
                        <div class="list-item-icon">
                            <span class="material-icons">person</span>
                        </div>
                        <div class="list-item-content">
                            <div class="list-item-title">${customer.name}</div>
                            <div class="list-item-subtitle">${customer.phone || ''}</div>
                        </div>
                        <div class="list-item-action">
                            <span class="material-icons">chevron_right</span>
                        </div>
                    </div>
                `).join('');
                
                // Aggiungi listener per visualizzare i dettagli cliente
                document.querySelectorAll('#recent-customers .list-item').forEach(item => {
                    item.addEventListener('click', () => {
                        this.showCustomerDetail(item.dataset.id);
                    });
                });
            } else {
                recentCustomersEl.innerHTML = '<p class="empty-message">Nessun cliente recente</p>';
            }
            
        } catch (error) {
            console.error('Dashboard initialization error:', error);
            this.showError('Errore nel caricamento della dashboard', error.message);
        }
    }

    // Le altre funzioni di inizializzazione vista verrebbero implementate qui...
    
    // Formatta una data 
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT', {
            weekday: 'short', 
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    // Aggiorna la navigazione attiva
    updateActiveNavigation(viewName) {
        // Sidebar
        document.querySelectorAll('#side-menu nav ul li').forEach(item => {
            if (item.dataset.view === viewName) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
        
        // Bottom nav
        document.querySelectorAll('#bottom-nav button').forEach(btn => {
            if (btn.dataset.view === viewName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }
    
    // Mostra o nascondi FAB in base alla vista
    toggleFAB(viewName) {
        const fab = document.getElementById('fab');
        
        if (['customers', 'appointments'].includes(viewName)) {
            fab.classList.remove('hidden');
            
            // Configura azione FAB in base alla vista
            if (viewName === 'customers') {
                fab.onclick = () => this.showNewCustomerForm();
            } else if (viewName === 'appointments') {
                fab.onclick = () => this.showNewAppointmentForm();
            }
        } else {
            fab.classList.add('hidden');
        }
    }
    
    // Ottieni il titolo della vista
    getViewTitle(viewName) {
        const titles = {
            dashboard: 'Dashboard',
            customers: 'Clienti',
            appointments: 'Appuntamenti',
            calendar: 'Calendario',
            settings: 'Impostazioni'
        };
        
        return titles[viewName] || 'PuntoTende CRM';
    }
    
    // Ricarica la vista corrente
    refreshCurrentView() {
        this.loadView(this.currentView);
    }
    
    // Mostra una modal di errore
    showError(title, message) {
        alert(`${title}: ${message}`);
        // In una versione più completa, usare una modal personalizzata
    }
    
    // Effettua il logout
    logout() {
        window.location.href = '/wp-login.php?action=logout';
    }
}

// Avvia l'app quando il DOM è pronto
document.addEventListener('DOMContentLoaded', () => {
    const app = new App();
    app.init();
});