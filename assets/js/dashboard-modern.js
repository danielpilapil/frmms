// FleetGo Modern Dashboard JavaScript
class FleetGoDashboard {
  constructor() {
    this.charts = {};
    this.refreshInterval = null;
    this.init();
  }

  init() {
    this.initializeCharts();
    this.bindEvents();
    this.startRealTimeUpdates();
  }

  // Initialize all charts
  initializeCharts() {
    this.initializeDemandChart();
    this.initializeUtilizationChart();
  }

  // Demand Forecast Chart
  initializeDemandChart() {
    const ctx = document.getElementById('demandForecastChart');
    if (!ctx) return;

    // Get data from PHP variables
    const labels = window.dashboardData?.monthlyLabels || [];
    const counts = window.dashboardData?.monthlyCounts || [];
    const forecastLabel = window.dashboardData?.forecastLabel || '';
    const forecastValue = window.dashboardData?.forecastValue || 0;

    // Generate next 7 days labels
    const next7Days = [];
    const forecastData = [];
    for (let i = 0; i < 7; i++) {
      const date = new Date();
      date.setDate(date.getDate() + i);
      next7Days.push(date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
      forecastData.push(Math.floor(forecastValue * (0.8 + Math.random() * 0.4))); // Simulate variation
    }

    this.charts.demand = new Chart(ctx, {
      type: 'line',
      data: {
        labels: next7Days,
        datasets: [{
          label: 'Demand Forecast',
          data: forecastData,
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          borderWidth: 3,
          fill: true,
          tension: 0.4,
          pointRadius: 4,
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            titleColor: '#f1f5f9',
            bodyColor: '#f1f5f9',
            borderColor: '#3b82f6',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 12
          }
        },
        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              color: '#94a3b8',
              font: {
                size: 12
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: 'rgba(148, 163, 184, 0.1)'
            },
            ticks: {
              color: '#94a3b8',
              font: {
                size: 12
              }
            }
          }
        }
      }
    });
  }

  // Utilization Chart
  initializeUtilizationChart() {
    const ctx = document.getElementById('utilizationChart');
    if (!ctx) return;

    const utilizationRate = window.dashboardData?.utilizationRate || 0;
    const overusedCount = window.dashboardData?.overusedCount || 0;
    const underusedCount = window.dashboardData?.underusedCount || 0;

    this.charts.utilization = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Utilized', 'Available', 'Underused', 'Overused'],
        datasets: [{
          data: [utilizationRate, 100 - utilizationRate, underusedCount, overusedCount],
          backgroundColor: [
            '#10b981',
            '#3b82f6',
            '#f59e0b',
            '#ef4444'
          ],
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: '#f1f5f9',
              padding: 20,
              font: {
                size: 12
              }
            }
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            titleColor: '#f1f5f9',
            bodyColor: '#f1f5f9',
            borderColor: '#3b82f6',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 12,
            callbacks: {
              label: function(context) {
                return context.label + ': ' + context.parsed + '%';
              }
            }
          }
        },
        cutout: '70%'
      }
    });
  }

  // Bind event handlers
  bindEvents() {
    // KPI card clicks
    document.querySelectorAll('.kpi-card').forEach(card => {
      card.addEventListener('click', (e) => {
        const action = card.dataset.action;
        if (action) {
          this.handleKpiClick(action);
        }
      });
    });

    // Refresh button
    const refreshBtn = document.getElementById('refreshDashboard');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => {
        this.refreshDashboard();
      });
    }

    // Export button
    const exportBtn = document.getElementById('exportDashboard');
    if (exportBtn) {
      exportBtn.addEventListener('click', () => {
        this.exportDashboardData();
      });
    }
  }

  // Handle KPI card clicks
  handleKpiClick(action) {
    const routes = {
      'vehicles': 'vehicles_all.php',
      'rentals': 'rentals_all.php',
      'maintenance': 'maintenance_all.php',
      'customers': 'customers_all.php',
      'reports': 'reports.php'
    };

    if (routes[action]) {
      window.location.href = routes[action];
    }
  }

  // Refresh dashboard data
  async refreshDashboard() {
    const refreshBtn = document.getElementById('refreshDashboard');
    if (refreshBtn) {
      refreshBtn.classList.add('loading');
      refreshBtn.disabled = true;
    }

    try {
      const response = await fetch('dashboard.php?ajax=kpi_data');
      const data = await response.json();
      
      this.updateKpiValues(data);
      this.showNotification('Dashboard refreshed successfully', 'success');
    } catch (error) {
      console.error('Error refreshing dashboard:', error);
      this.showNotification('Error refreshing dashboard', 'error');
    } finally {
      if (refreshBtn) {
        refreshBtn.classList.remove('loading');
        refreshBtn.disabled = false;
      }
    }
  }

  // Update KPI values with animation
  updateKpiValues(data) {
    const kpiElements = {
      'totalVehicles': document.getElementById('kpi-total-vehicles'),
      'availableToday': document.getElementById('kpi-available'),
      'activeRentals': document.getElementById('kpi-active-rentals'),
      'todayRevenue': document.getElementById('kpi-today-revenue'),
      'maintDue': document.getElementById('kpi-maintenance-due'),
      'overdueRentals': document.getElementById('kpi-overdue-rentals')
    };

    Object.keys(kpiElements).forEach(key => {
      const element = kpiElements[key];
      if (element && data[key] !== undefined) {
        // Add animation class
        element.classList.add('updating');
        
        setTimeout(() => {
          if (key === 'todayRevenue') {
            element.textContent = '₱' + Number(data[key]).toLocaleString();
          } else {
            element.textContent = Number(data[key]).toLocaleString();
          }
          element.classList.remove('updating');
        }, 300);
      }
    });
  }

  // Export dashboard data
  exportDashboardData() {
    const data = {
      timestamp: new Date().toISOString(),
      kpis: {
        totalVehicles: window.dashboardData?.totalVehicles || 0,
        availableToday: window.dashboardData?.availableToday || 0,
        activeRentals: window.dashboardData?.activeRentals || 0,
        todayRevenue: window.dashboardData?.todayRevenue || 0,
        maintenanceDue: window.dashboardData?.maintenanceDue || 0,
        overdueRentals: window.dashboardData?.overdueRentals || 0
      },
      forecasts: {
        demand: window.dashboardData?.forecastValue || 0,
        maintenanceOverdue: window.dashboardData?.maintenanceOverdueCount || 0,
        maintenanceDueSoon: window.dashboardData?.maintenanceDueSoonCount || 0,
        utilizationRate: window.dashboardData?.utilizationRate || 0
      }
    };

    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `dashboard-export-${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    this.showNotification('Dashboard data exported successfully', 'success');
  }

  // Start real-time updates
  startRealTimeUpdates() {
    // Update every 30 seconds
    this.refreshInterval = setInterval(() => {
      this.refreshDashboard();
    }, 30000);
  }

  // Show notification
  showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
      <div class="notification-content">
        <i class="fas ${this.getNotificationIcon(type)}"></i>
        <span>${message}</span>
      </div>
    `;

    document.body.appendChild(notification);

    // Auto remove after 3 seconds
    setTimeout(() => {
      notification.classList.add('notification-hiding');
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, 300);
    }, 3000);
  }

  // Get notification icon based on type
  getNotificationIcon(type) {
    const icons = {
      success: 'fa-check-circle',
      error: 'fa-exclamation-circle',
      warning: 'fa-exclamation-triangle',
      info: 'fa-info-circle'
    };
    return icons[type] || icons.info;
  }

  // Cleanup
  destroy() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval);
    }
    
    // Destroy charts
    Object.values(this.charts).forEach(chart => {
      if (chart && typeof chart.destroy === 'function') {
        chart.destroy();
      }
    });
  }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  window.dashboard = new FleetGoDashboard();
  
  // Initialize collapsible sections
  initializeCollapsibleSections();
  
  // Initialize refresh functionality
  initializeRefreshButton();
  
  // Initialize export functionality
  initializeExportButton();
  
  // Initialize forecast chart
  initializeForecastChart();
});

// Initialize forecast chart
function initializeForecastChart() {
  const ctx = document.getElementById('demandForecastChart');
  if (!ctx) return;
  
  // Fetch forecast data
  fetch('?ajax=forecast_data')
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      if (!data.success) {
        throw new Error(data.error || 'Failed to fetch forecast data');
      }
      
      const forecastData = data.data;
      
      // Destroy existing chart instance safely
      const existingChart = Chart.getChart(ctx);
      if (existingChart) {
        existingChart.destroy();
      }
      
      // Create new chart
      window.demandForecastChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: forecastData.monthlyLabels || [],
          datasets: [{
            label: 'Monthly Rentals',
            data: forecastData.monthlyCounts || [],
            borderColor: '#00ccff',
            backgroundColor: 'rgba(0, 204, 255, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: true
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              labels: {
                color: '#ffffff',
                font: {
                  size: 12
                }
              }
            },
            tooltip: {
              mode: 'index',
              intersect: false
            }
          },
          scales: {
            x: {
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#ffffff'
              }
            },
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#ffffff'
              }
            }
          }
        }
      });
    })
    .catch(error => {
      console.error('Error loading forecast chart:', error);
      showNotification(`Failed to load forecast chart: ${error.message}`, 'error');
    });
}

// Collapsible sections functionality
function initializeCollapsibleSections() {
  // Main toggle button
  const mainToggleBtn = document.getElementById('toggleSections');
  
  // Individual section toggles
  const forecastToggleBtn = document.getElementById('toggleForecast');
  const activityToggleBtn = document.getElementById('toggleActivity');
  const alertsToggleBtn = document.getElementById('toggleAlerts');
  
  // All collapsible sections
  const sections = document.querySelectorAll('.collapsible-section');
  
  if (mainToggleBtn && sections.length > 0) {
    mainToggleBtn.addEventListener('click', () => {
      const isCollapsed = sections[0].classList.contains('collapsed');
      
      sections.forEach(section => {
        if (isCollapsed) {
          section.classList.remove('collapsed');
          mainToggleBtn.querySelector('span').textContent = 'Compact View';
          mainToggleBtn.querySelector('i').className = 'fas fa-eye';
        } else {
          section.classList.add('collapsed');
          mainToggleBtn.querySelector('span').textContent = 'Expand View';
          mainToggleBtn.querySelector('i').className = 'fas fa-eye-slash';
        }
      });
      
      // Save preference
      localStorage.setItem('dashboardCompactMode', !isCollapsed);
    });
    
    // Restore saved preference
    const savedState = localStorage.getItem('dashboardCompactMode');
    if (savedState === 'true') {
      sections.forEach(section => {
        section.classList.add('collapsed');
      });
      mainToggleBtn.querySelector('span').textContent = 'Expand View';
      mainToggleBtn.querySelector('i').className = 'fas fa-eye-slash';
    }
  }
  
  // Individual section toggles
  [forecastToggleBtn, activityToggleBtn, alertsToggleBtn].forEach(btn => {
    if (btn) {
      btn.addEventListener('click', () => {
        const section = btn.closest('.collapsible-section');
        if (section) {
          const isCollapsed = section.classList.contains('collapsed');
          
          if (isCollapsed) {
            section.classList.remove('collapsed');
            btn.querySelector('span').textContent = 'Hide';
            btn.querySelector('i').className = 'fas fa-eye';
          } else {
            section.classList.add('collapsed');
            btn.querySelector('span').textContent = 'Show';
            btn.querySelector('i').className = 'fas fa-eye-slash';
          }
        }
      });
    }
  });
}

// Refresh button functionality
function initializeRefreshButton() {
  const refreshBtn = document.getElementById('refreshDashboard');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', async () => {
      // Add loading state
      const originalContent = refreshBtn.innerHTML;
      refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Refreshing...</span>';
      refreshBtn.disabled = true;
      
      // Prepare all refresh promises
      const refreshPromises = [
        // KPI data
        fetch('?ajax=kpi_data').then(async response => {
          if (!response.ok) {
            throw new Error(`KPI: HTTP ${response.status}: ${response.statusText}`);
          }
          const data = await response.json();
          if (!data.success && data.error) {
            throw new Error(`KPI: ${data.error}`);
          }
          return { type: 'kpi', data: data, success: true };
        }).catch(error => ({ type: 'kpi', error: error.message, success: false })),
        
        // Recent rentals
        fetch('?ajax=recent_rentals').then(async response => {
          if (!response.ok) {
            throw new Error(`Rentals: HTTP ${response.status}: ${response.statusText}`);
          }
          const data = await response.json();
          if (!data.success) {
            throw new Error(`Rentals: ${data.error || 'Failed to fetch recent rentals'}`);
          }
          return { type: 'rentals', data: data, success: true };
        }).catch(error => ({ type: 'rentals', error: error.message, success: false })),
        
        // Maintenance forecast
        fetch('?ajax=maintenance_forecast').then(async response => {
          if (!response.ok) {
            throw new Error(`Maintenance: HTTP ${response.status}: ${response.statusText}`);
          }
          const data = await response.json();
          if (!data.success) {
            throw new Error(`Maintenance: ${data.error || 'Failed to fetch maintenance forecast'}`);
          }
          return { type: 'maintenance', data: data, success: true };
        }).catch(error => ({ type: 'maintenance', error: error.message, success: false })),
        
        // Forecast data
        fetch('?ajax=forecast_data').then(async response => {
          if (!response.ok) {
            throw new Error(`Forecast: HTTP ${response.status}: ${response.statusText}`);
          }
          const data = await response.json();
          if (!data.success) {
            throw new Error(`Forecast: ${data.error || 'Failed to fetch forecast data'}`);
          }
          return { type: 'forecast', data: data, success: true };
        }).catch(error => ({ type: 'forecast', error: error.message, success: false }))
      ];
      
      try {
        // Wait for all promises to settle
        const results = await Promise.allSettled(refreshPromises);
        
        let successCount = 0;
        let errorMessages = [];
        
        // Process results
        for (const result of results) {
          if (result.status === 'fulfilled') {
            const { type, data, success, error } = result.value;
            
            if (success) {
              successCount++;
              
              // Update UI based on type
              if (type === 'kpi') {
                updateKPIValues(data);
              } else if (type === 'rentals') {
                await refreshRecentRentalsWithData(data.data);
              } else if (type === 'maintenance') {
                await refreshMaintenanceForecastWithData(data.data);
              } else if (type === 'forecast') {
                await refreshForecastChartWithData(data.data);
              }
            } else {
              errorMessages.push(error);
            }
          } else {
            errorMessages.push(result.reason.message);
          }
        }
        
        // Show appropriate notification
        if (successCount >= 2) {
          showNotification('Dashboard refreshed successfully!', 'success');
        } else if (successCount === 1) {
          showNotification('Partially refreshed. Some sections failed.', 'warning');
        } else {
          showNotification(`Refresh failed: ${errorMessages.join(', ')}`, 'error');
        }
        
        // Log detailed errors for debugging
        if (errorMessages.length > 0) {
          console.error('Refresh errors:', errorMessages);
        }
        
      } catch (error) {
        console.error('Unexpected refresh error:', error);
        showNotification(`Unexpected refresh error: ${error.message}`, 'error');
      } finally {
        // Restore button state
        refreshBtn.innerHTML = originalContent;
        refreshBtn.disabled = false;
      }
    });
  }
}

// Update KPI values
function updateKPIValues(kpiData) {
  if (document.getElementById('kpi-total-vehicles')) {
    document.getElementById('kpi-total-vehicles').textContent = kpiData.totalVehicles.toLocaleString();
  }
  if (document.getElementById('kpi-available')) {
    document.getElementById('kpi-available').textContent = kpiData.availableToday.toLocaleString();
  }
  if (document.getElementById('kpi-active-rentals')) {
    document.getElementById('kpi-active-rentals').textContent = kpiData.activeRentals.toLocaleString();
  }
  if (document.getElementById('kpi-today-revenue')) {
    document.getElementById('kpi-today-revenue').textContent = '₱' + kpiData.todayRevenue.toLocaleString();
  }
  if (document.getElementById('kpi-maintenance-due')) {
    document.getElementById('kpi-maintenance-due').textContent = kpiData.maintDue.toLocaleString();
  }
  if (document.getElementById('kpi-overdue-rentals')) {
    document.getElementById('kpi-overdue-rentals').textContent = kpiData.overdueRentals.toLocaleString();
  }
}

// Refresh recent rentals with data
async function refreshRecentRentalsWithData(rentalsData) {
  const existingTable = document.querySelector('.activity-table tbody');
  if (!existingTable) return;
  
  // Clear existing content
  existingTable.innerHTML = '';
  
  // Add new rental rows
  if (rentalsData && rentalsData.length > 0) {
    rentalsData.forEach(rental => {
      const row = document.createElement('tr');
      const startDate = rental.start_date ? new Date(rental.start_date).toLocaleDateString() : 'N/A';
      const endDate = rental.end_date ? new Date(rental.end_date).toLocaleDateString() : 'N/A';
      const statusClass = rental.status ? rental.status.toLowerCase() : 'unknown';
      
      row.innerHTML = `
        <td>${rental.full_name || 'N/A'}</td>
        <td>${rental.vehicle_name || 'N/A'}</td>
        <td>${startDate}</td>
        <td>${endDate}</td>
        <td>
          <span class="status-badge ${statusClass}">
            ${rental.status || 'N/A'}
          </span>
        </td>
      `;
      existingTable.appendChild(row);
    });
  } else {
    const noDataRow = document.createElement('tr');
    noDataRow.innerHTML = `
      <td colspan="5" style="text-align: center; color: var(--text-muted);">
        No recent rentals found
      </td>
    `;
    existingTable.appendChild(noDataRow);
  }
}

// Refresh maintenance forecast with data
async function refreshMaintenanceForecastWithData(maintenanceData) {
  const existingForecast = document.querySelector('.forecast-list');
  if (!existingForecast) return;
  
  // Clear existing content
  existingForecast.innerHTML = '';
  
  // Add new maintenance items
  if (maintenanceData && maintenanceData.length > 0) {
    maintenanceData.forEach(maintenance => {
      const li = document.createElement('li');
      li.innerHTML = `
        <span>${maintenance.vehicle_name}</span>
        <span>${maintenance.days_until} days</span>
      `;
      existingForecast.appendChild(li);
    });
  } else {
    const noMaintenanceItem = document.createElement('li');
    noMaintenanceItem.textContent = 'No maintenance due';
    existingForecast.appendChild(noMaintenanceItem);
  }
}

// Refresh forecast chart with data
async function refreshForecastChartWithData(forecastData) {
  const ctx = document.getElementById('demandForecastChart');
  if (!ctx) return;
  
  // Destroy existing chart instance if it exists
  if (window.demandForecastChart) {
    window.demandForecastChart.destroy();
  }
  
  // Create new chart
  window.demandForecastChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: forecastData.monthlyLabels || [],
      datasets: [{
        label: 'Monthly Rentals',
        data: forecastData.monthlyCounts || [],
        borderColor: '#00ccff',
        backgroundColor: 'rgba(0, 204, 255, 0.1)',
        borderWidth: 2,
        tension: 0.4,
        fill: true
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          labels: {
            color: '#ffffff',
            font: {
              size: 12
            }
          }
        },
        tooltip: {
          mode: 'index',
          intersect: false
        }
      },
      scales: {
        x: {
          grid: {
            color: 'rgba(255, 255, 255, 0.1)'
          },
          ticks: {
            color: '#ffffff'
          }
        },
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(255, 255, 255, 0.1)'
          },
          ticks: {
            color: '#ffffff'
          }
        }
      }
    }
  });
}

// Refresh recent rentals
async function refreshRecentRentals() {
  try {
    const response = await fetch('?ajax=recent_rentals');
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const data = await response.json();
    if (!data.success) {
      throw new Error(data.error || 'Failed to fetch recent rentals');
    }
    
    const existingTable = document.querySelector('.activity-table tbody');
    if (!existingTable) return;
    
    // Clear existing content
    existingTable.innerHTML = '';
    
    // Add new rental rows
    if (data.data && data.data.length > 0) {
      data.data.forEach(rental => {
        const row = document.createElement('tr');
        const startDate = rental.start_date ? new Date(rental.start_date).toLocaleDateString() : 'N/A';
        const endDate = rental.end_date ? new Date(rental.end_date).toLocaleDateString() : 'N/A';
        const statusClass = rental.status ? rental.status.toLowerCase() : 'unknown';
        
        row.innerHTML = `
          <td>${rental.customer_name || 'N/A'}</td>
          <td>${rental.vehicle_name || 'N/A'}</td>
          <td>${startDate}</td>
          <td>${endDate}</td>
          <td>
            <span class="status-badge ${statusClass}">
              ${rental.status || 'N/A'}
            </span>
          </td>
        `;
        existingTable.appendChild(row);
      });
    } else {
      const noDataRow = document.createElement('tr');
      noDataRow.innerHTML = `
        <td colspan="5" style="text-align: center; color: var(--text-muted);">
          No recent rentals found
        </td>
      `;
      existingTable.appendChild(noDataRow);
    }
    
  } catch (error) {
    console.error('Error refreshing recent rentals:', error);
    showNotification(`Failed to refresh recent rentals: ${error.message}`, 'error');
  }
}

// Refresh maintenance forecast
async function refreshMaintenanceForecast() {
  try {
    const response = await fetch('?ajax=maintenance_forecast');
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const data = await response.json();
    if (!data.success) {
      throw new Error(data.error || 'Failed to fetch maintenance forecast');
    }
    
    const existingForecast = document.querySelector('.forecast-list');
    if (!existingForecast) return;
    
    // Clear existing content
    existingForecast.innerHTML = '';
    
    // Add new maintenance items
    if (data.data && data.data.length > 0) {
      data.data.forEach(maintenance => {
        const li = document.createElement('li');
        li.innerHTML = `
          <span>${maintenance.vehicle_name}</span>
          <span>${maintenance.days_until} days</span>
        `;
        existingForecast.appendChild(li);
      });
    } else {
      const noMaintenanceItem = document.createElement('li');
      noMaintenanceItem.textContent = 'No maintenance due';
      existingForecast.appendChild(noMaintenanceItem);
    }
    
  } catch (error) {
    console.error('Error refreshing maintenance forecast:', error);
    showNotification(`Failed to refresh maintenance forecast: ${error.message}`, 'error');
  }
}

// Export button functionality
function initializeExportButton() {
  const exportBtn = document.getElementById('exportDashboard');
  if (exportBtn) {
    exportBtn.addEventListener('click', async () => {
      // Add loading state
      const originalContent = exportBtn.innerHTML;
      exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Exporting...</span>';
      exportBtn.disabled = true;
      
      try {
        // Get current dashboard data
        const kpiData = await fetch('?ajax=kpi_data');
        const kpi = await kpiData.json();
        
        // Create CSV content
        const csvContent = createDashboardCSV(kpi);
        
        // Download CSV file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `fleetgo-dashboard-${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showNotification('Dashboard exported successfully!', 'success');
        
      } catch (error) {
        console.error('Export error:', error);
        showNotification('Failed to export dashboard', 'error');
      } finally {
        // Restore button state
        exportBtn.innerHTML = originalContent;
        exportBtn.disabled = false;
      }
    });
  }
}

// Create CSV content for dashboard export
function createDashboardCSV(data) {
  const headers = ['Metric', 'Value', 'Timestamp'];
  const rows = [
    ['Total Vehicles', data.totalVehicles, new Date().toISOString()],
    ['Available Vehicles', data.availableToday, new Date().toISOString()],
    ['Active Rentals', data.activeRentals, new Date().toISOString()],
    ['Maintenance Due Today', data.maintDue, new Date().toISOString()],
    ['Today Revenue', data.todayRevenue, new Date().toISOString()],
    ['Overdue Rentals', data.overdueRentals, new Date().toISOString()],
    ['Utilization Rate', data.utilizationRate + '%', new Date().toISOString()]
  ];
  
  const csvContent = [
    headers.join(','),
    ...rows.map(row => row.map(cell => `"${cell}"`).join(','))
  ].join('\n');
  
  return csvContent;
}

// Show notification
function showNotification(message, type = 'info') {
  // Create notification element
  const notification = document.createElement('div');
  notification.className = `notification notification-${type}`;
  notification.innerHTML = `
    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
    <span>${message}</span>
  `;
  
  // Add to page
  document.body.appendChild(notification);
  
  // Auto remove after 3 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  }, 3000);
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
  if (window.dashboard) {
    window.dashboard.destroy();
  }
});

// Add notification styles
const notificationStyles = `
  .notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    min-width: 300px;
    max-width: 400px;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    transform: translateX(100%);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .notification.notification-show {
    transform: translateX(0);
  }

  .notification.notification-hiding {
    transform: translateX(100%);
    opacity: 0;
  }

  .notification-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    color: white;
  }

  .notification-success .notification-content {
    background: linear-gradient(135deg, #10b981, #059669);
  }

  .notification-error .notification-content {
    background: linear-gradient(135deg, #ef4444, #dc2626);
  }

  .notification-warning .notification-content {
    background: linear-gradient(135deg, #f59e0b, #d97706);
  }

  .notification-info .notification-content {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
  }

  .notification i {
    font-size: 18px;
  }

  .notification span {
    font-weight: 500;
    font-size: 14px;
  }

  .updating {
    animation: pulse 0.6s ease-in-out;
  }

  @keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.05); opacity: 0.8; }
  }
`;

// Add styles to head
const styleSheet = document.createElement('style');
styleSheet.textContent = notificationStyles;
document.head.appendChild(styleSheet);

// Show notification helper
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    const notification = document.querySelector('.notification');
    if (notification) {
      notification.classList.add('notification-show');
    }
  }, 100);
});
