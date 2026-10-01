import SwiftUI

struct DailyOpsView: View {
    @State private var selectedDate = Date()
    @State private var dayOps: DailyOpsDay?
    @State private var locations: [LocationOption] = []
    @State private var workLocations: [LocationOption] = []
    @State private var isLoading = true
    @State private var showEditSheet = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    // Date picker
                    DatePicker(
                        "Date",
                        selection: $selectedDate,
                        displayedComponents: .date
                    )
                    .datePickerStyle(.compact)
                    .padding(.horizontal)
                    .onChange(of: selectedDate) { _, _ in
                        Task { await loadDay() }
                    }

                    if isLoading {
                        ProgressView()
                            .padding(.top, 40)
                    } else if let ops = dayOps {
                        opsContent(ops)
                    }
                }
                .padding()
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Daily Ops")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Edit") { showEditSheet = true }
                }
            }
            .sheet(isPresented: $showEditSheet) {
                EditDailyOpsView(
                    date: selectedDate,
                    current: dayOps,
                    locations: locations,
                    workLocations: workLocations
                ) { await loadDay() }
            }
            .task {
                await loadReferenceData()
                await loadDay()
            }
        }
    }

    @ViewBuilder
    private func opsContent(_ ops: DailyOpsDay) -> some View {
        VStack(spacing: 14) {
            // On-call section
            if ops.ckOnCall || ops.dcOnCall {
                HStack(spacing: 12) {
                    Image(systemName: "phone.fill")
                        .foregroundStyle(.red)
                    if ops.ckOnCall { Text("CK On Call").font(.subheadline) }
                    if ops.dcOnCall { Text("DC On Call").font(.subheadline) }
                    Spacer()
                }
                .padding()
                .background(.red.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }

            // CK locations
            locationCard(
                person: "CK",
                work: ops.ckWorkLocation,
                night: ops.ckLocation,
                color: .teal
            )

            // DC locations
            locationCard(
                person: "DC",
                work: ops.dcWorkLocation,
                night: ops.dcLocation,
                color: .blue
            )

            // Meal / Notes
            if !ops.notes.isEmpty {
                HStack {
                    Image(systemName: "fork.knife")
                        .foregroundStyle(.orange)
                    Text(ops.notes)
                        .font(.subheadline)
                    Spacer()
                }
                .padding()
                .background(.orange.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    private func locationCard(person: String, work: LocationInfo, night: LocationInfo, color: Color) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(person)
                .font(.headline)
                .foregroundStyle(color)

            HStack(spacing: 16) {
                if !work.name.isEmpty {
                    Label(work.name, systemImage: "briefcase.fill")
                        .font(.subheadline)
                }
                if !night.name.isEmpty {
                    Label(night.name, systemImage: "moon.fill")
                        .font(.subheadline)
                }
                if work.name.isEmpty && night.name.isEmpty {
                    Text("Not set")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding()
        .background(color.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func loadReferenceData() async {
        do {
            async let loc = APIService.shared.fetchLocations()
            async let wloc = APIService.shared.fetchWorkLocations()
            let (l, w) = try await (loc, wloc)
            locations = l
            workLocations = w
        } catch {
            print("Load reference data error: \(error)")
        }
    }

    private func loadDay() async {
        isLoading = true
        let dateStr = DateFormatters.apiDate.string(from: selectedDate)
        do {
            let days = try await APIService.shared.fetchDailyOps(start: dateStr, end: dateStr)
            dayOps = days.first
        } catch {
            print("Load day error: \(error)")
        }
        isLoading = false
    }
}
