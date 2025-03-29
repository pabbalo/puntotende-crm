/**
 * PuntoTende CRM - Mobile Web App
 * Calendar Sync Module
 */

export default class SyncModule {
    constructor(app) {
        this.app = app;
        this.syncStatus = null;
    }
    
    /**
     * Inizializza il modulo di sincronizzazione
     */
    async init() {
        // Aggiungi un pulsante di sincronizzazione nella vista calendario
        this.addSyncButton();
        
        // Controlla lo stato della sincronizzazione
        await this.checkSyncStatus();
    }
    
    /**
     * Aggiunge un pulsante di sincronizzazione alla vista calendario
     */
    addSyncButton() {
        // Cerca l'intestazione del calendario
        const calendarHeader = document.querySelector('.calendar-view .date-navigation');
        
        if (calendarHeader) {
            // Crea pulsante sincronizzazione
            const syncButton = document.createElement('button');
            syncButton.id = 'sync-calendar-btn';
            syncButton.className = 'icon-btn sync-btn';
            syncButton.innerHTML = '<span class="material-icons">sync</span>';
            syncButton.title = 'Sincronizza calendario';
            
            // Aggiungi il pulsante all'header
            calendarHeader.appendChild(syncButton);
            
            // Aggiungi event listener
            syncButton.addEventListener('click', () => this.triggerSync());
        }
        
        // Aggiungi anche un'opzione nelle impostazioni
        const settingsContainer = document.getElementById('settings-container');
        if (settingsContainer) {
            const syncSection = document.createElement('div');
            syncSection.className = 'settings-section';
            syncSection.innerHTML = `
                <h3>Sincronizzazione Calendario</h3>
                <div class="settings-item">
                    <div class="settings-info">
                        <div class="settings-label">Ultima sincronizzazione</div>
                        <div class="settings-value" id="last-sync">Caricamento...</div>
                    </div>
                    <button id="settings-sync-btn" class="btn-outline">Sincronizza ora</button>
                </div>
            `;
            
            settingsContainer.appendChild(syncSection);
            
            // Aggiungi event listener
            const settingsSyncBtn = document.getElementById('settings-sync-btn');
            if (settingsSyncBtn) {
                settingsSyncBtn.addEventListener('click', () => this.triggerSync());
            }
        }
    }
    
    /**
     * Controlla lo stato della sincronizzazione
     */
    async checkSyncStatus() {
        try {
            const response = await fetch(`${this.app.config.apiBase}/sync/calendar`, {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Errore nel controllo dello stato di sincronizzazione');
            }
            
            this.syncStatus = await response.json();
            
            // Aggiorna UI con lo stato della sincronizzazione
            this.updateSyncUI();
            
            return this.syncStatus;
        } catch (error) {
            console.error('Error checking sync status:', error);
            return null;
        }
    }
    
    /**
     * Avvia la sincronizzazione
     */
    async triggerSync() {
        // Mostra indicatore di caricamento sul pulsante
        this.setSyncButtonLoading(true);
        
        try {
            const response = await fetch(`${this.app.config.apiBase}/sync/calendar`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.app.wpNonce
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || 'Errore nella sincronizzazione');
            }
            
            const result = await response.json();
            
            // Mostra notifica
            this.app.showNotification('Sincronizzazione avviata');
            
            // Dopo 3 secondi, controlla lo stato della sincronizzazione
            setTimeout(() => {
                this.checkSyncStatus();
                
                // Ricarica gli eventi se siamo nella vista calendario
                if (this.app.currentView === 'calendar') {
                    this.app.modules.calendar.loadEvents();
                }
            }, 3000);
            
            return result;
        } catch (error) {
            console.error('Error triggering sync:', error);
            this.app.showError('Errore nella sincronizzazione', error.message);
        } finally {
            // Rimuovi indicatore di caricamento dal pulsante
            this.setSyncButtonLoading(false);
        }
    }
    
    /**
     * Aggiorna l'interfaccia utente con lo stato della sincronizzazione
     */
    updateSyncUI() {
        // Ag