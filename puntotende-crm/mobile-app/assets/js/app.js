/**
 * PuntoTende CRM - Mobile Web App
 * Versione semplificata per debug
 */

document.addEventListener('DOMContentLoaded', () => {
    console.log('App inizializzata - versione debug');

    // Nascondi splash screen
    setTimeout(() => {
        document.getElementById('splash-screen').classList.add('hidden');
    }, 1000);

    // Verifica autenticazione
    checkAuth()
        .then(status => {
            console.log('Stato autenticazione:', status);
            
            if (status.logged_in) {
                // Utente autenticato, mostra app
                document.getElementById('app-container').classList.remove('hidden');
                document.getElementById('login-container').classList.add('hidden');
                
                // Mostra info utente
                document.getElementById('user-name').textContent = status.user.name || 'Utente';
                
                // Visualizza messaggio di debug
                const viewContainer = document.getElementById('view-container');
                if (viewContainer) {
                    viewContainer.innerHTML = `
                        <div style="padding: 20px; text-align: center;">
                            <h3>App in modalità debug</h3>
                            <p>Autenticazione riuscita!</p>
                            <p>Utente: ${status.user.name}</p>
                            <button id="test-api-btn" class="btn-primary" style="margin-top: 20px;">
                                Testa API Dashboard
                            </button>
                        </div>
                    `;
                    
                    // Aggiungi test API
                    document.getElementById('test-api-btn').addEventListener('click', testDashboardAPI);
                }
            } else {
                // Utente non autenticato, mostra login
                document.getElementById('login-container').classList.remove('hidden');
                document.getElementById('app-container').classList.add('hidden');
                
                // Aggiungi event listener al pulsante login
                document.getElementById('login-btn').addEventListener('click', () => {
                    window.location.href = '/wp-login.php?redirect_to=' + encodeURIComponent(window.location.href);
                });
            }
        })
        .catch(error => {
            console.error('Errore verifica autenticazione:', error);
            // Mostra errore
            document.getElementById('splash-screen').classList.add('hidden');
            document.getElementById('login-container').classList.remove('hidden');
            document.getElementById('login-container').innerHTML = `
                <div class="login-box">
                    <h2>Errore</h2>
                    <p>Si è verificato un errore durante la verifica dell'autenticazione:</p>
                    <p style="color: red;">${error.message}</p>
                    <button class="btn-primary" onclick="window.location.reload()">Riprova</button>
                </div>
            `;
        });
    
    // Funzione per verificare autenticazione
    async function checkAuth() {
        try {
            const response = await fetch('/wp-json/ptcrm/v1/auth/status', {
                credentials: 'same-origin' // Importante per inviare cookie autenticazione
            });
            
            if (!response.ok) {
                throw new Error(`Errore API (${response.status}): ${response.statusText}`);
            }
            
            return await response.json();
        } catch (error) {
            console.error('Errore nella chiamata API:', error);
            throw new Error('Impossibile contattare il server WordPress. Controlla che gli endpoint API siano configurati correttamente.');
        }
    }
    
    // Funzione per testare l'API dashboard
    async function testDashboardAPI() {
        try {
            const viewContainer = document.getElementById('view-container');
            viewContainer.innerHTML = '<div style="padding: 20px; text-align: center;"><p>Caricamento dati...</p></div>';
            
            const response = await fetch('/wp-json/ptcrm/v1/dashboard', {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`Errore API (${response.status}): ${response.statusText}`);
            }
            
            const data = await response.json();
            console.log('Dati dashboard:', data);
            
            // Mostra risultati
            viewContainer.innerHTML = `
                <div style="padding: 20px;">
                    <h3>Test API riuscito!</h3>
                    <p>Risposta dell'API dashboard:</p>
                    <pre style="background: #f5f5f5; padding: 10px; overflow: auto; max-height: 300px;">
${JSON.stringify(data, null, 2)}
                    </pre>
                </div>
            `;
        } catch (error) {
            console.error('Errore test API dashboard:', error);
            document.getElementById('view-container').innerHTML = `
                <div style="padding: 20px; text-align: center;">
                    <h3>Errore test API</h3>
                    <p style="color: red;">${error.message}</p>
                    <button id="retry-btn" class="btn-primary" style="margin-top: 20px;">Riprova</button>
                </div>
            `;
            document.getElementById('retry-btn').addEventListener('click', testDashboardAPI);
        }
    }
    
    // Configura menu laterale
    document.getElementById('menu-toggle')?.addEventListener('click', toggleMenu);
    document.getElementById('close-menu-btn')?.addEventListener('click', toggleMenu);
    document.getElementById('overlay')?.addEventListener('click', toggleMenu);
    
    function toggleMenu() {
        const sideMenu = document.getElementById('side-menu');
        const overlay = document.getElementById('overlay');
        
        if (sideMenu.classList.contains('open')) {
            sideMenu.classList.remove('open');
            overlay.classList.remove('visible');
        } else {
            sideMenu.classList.add('open');
            overlay.classList.add('visible');
        }
    }
});