import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var authManager: AuthManager

    var body: some View {
        TabView {
            DashboardView()
                .tabItem {
                    Label("Dashboard", systemImage: "house.fill")
                }

            CalendarView()
                .tabItem {
                    Label("Calendar", systemImage: "calendar")
                }

            ContactsListView()
                .tabItem {
                    Label("Contacts", systemImage: "person.2.fill")
                }

            DailyOpsView()
                .tabItem {
                    Label("Ops", systemImage: "mappin.and.ellipse")
                }
        }
        .tint(.blue)
    }
}
