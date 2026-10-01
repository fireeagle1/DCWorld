import SwiftUI

struct ContactsListView: View {
    @State private var contacts: [Contact] = []
    @State private var searchText = ""
    @State private var isLoading = true
    @State private var selectedContact: Contact?
    @State private var showAddContact = false

    private var filteredContacts: [Contact] {
        if searchText.isEmpty { return contacts }
        let term = searchText.lowercased()
        return contacts.filter {
            $0.knownAs.lowercased().contains(term) ||
            $0.firstName.lowercased().contains(term) ||
            $0.lastName.lowercased().contains(term)
        }
    }

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    ProgressView()
                } else if contacts.isEmpty {
                    ContentUnavailableView(
                        "No Contacts",
                        systemImage: "person.2",
                        description: Text("Add your first contact to get started")
                    )
                } else {
                    List(filteredContacts) { contact in
                        ContactRow(contact: contact)
                            .onTapGesture { selectedContact = contact }
                    }
                    .listStyle(.plain)
                }
            }
            .navigationTitle("Contacts")
            .searchable(text: $searchText, prompt: "Search contacts")
            .onChange(of: searchText) { _, newValue in
                Task { await search(newValue) }
            }
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showAddContact = true } label: {
                        Image(systemName: "plus")
                    }
                }
            }
            .sheet(item: $selectedContact) { contact in
                ContactDetailView(contact: contact) { await loadContacts() }
            }
            .sheet(isPresented: $showAddContact) {
                AddContactView { await loadContacts() }
            }
            .task { await loadContacts() }
            .refreshable { await loadContacts() }
        }
    }

    private func loadContacts() async {
        isLoading = true
        do {
            contacts = try await APIService.shared.fetchContacts()
        } catch {
            print("Load contacts error: \(error)")
        }
        isLoading = false
    }

    private func search(_ term: String) async {
        guard !term.isEmpty else {
            await loadContacts()
            return
        }
        do {
            contacts = try await APIService.shared.fetchContacts(search: term)
        } catch {
            print("Search error: \(error)")
        }
    }
}

// MARK: - Contact Row

struct ContactRow: View {
    let contact: Contact

    var body: some View {
        HStack(spacing: 12) {
            AsyncImage(url: contact.photoURL.flatMap { URL(string: $0) }) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                Image(systemName: "person.circle.fill")
                    .resizable()
                    .foregroundStyle(.gray.opacity(0.4))
            }
            .frame(width: 44, height: 44)
            .clipShape(Circle())

            VStack(alignment: .leading, spacing: 2) {
                Text(contact.knownAs)
                    .font(.body)
                    .fontWeight(.medium)
                if !contact.phone.isEmpty {
                    Text(contact.phone)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Contact Detail

struct ContactDetailView: View {
    let contact: Contact
    let onUpdate: () async -> Void
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack {
                        Spacer()
                        VStack(spacing: 8) {
                            AsyncImage(url: contact.photoURL.flatMap { URL(string: $0) }) { image in
                                image.resizable().scaledToFill()
                            } placeholder: {
                                Image(systemName: "person.circle.fill")
                                    .resizable()
                                    .foregroundStyle(.gray.opacity(0.3))
                            }
                            .frame(width: 80, height: 80)
                            .clipShape(Circle())

                            Text(contact.knownAs)
                                .font(.title2)
                                .fontWeight(.bold)
                            Text(contact.fullName)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                    }
                    .listRowBackground(Color.clear)
                }

                if !contact.phone.isEmpty || !contact.email.isEmpty {
                    Section("Contact Info") {
                        if !contact.phone.isEmpty {
                            Label(contact.phone, systemImage: "phone.fill")
                        }
                        if !contact.email.isEmpty {
                            Label(contact.email, systemImage: "envelope.fill")
                        }
                    }
                }

                if let dob = contact.dob, !dob.isEmpty {
                    Section("Birthday") {
                        Label(dob, systemImage: "birthday.cake.fill")
                    }
                }

                if !contact.streetAddress.isEmpty || !contact.city.isEmpty {
                    Section("Address") {
                        VStack(alignment: .leading, spacing: 2) {
                            if !contact.streetAddress.isEmpty { Text(contact.streetAddress) }
                            HStack {
                                if !contact.city.isEmpty { Text(contact.city) }
                                if !contact.postcode.isEmpty { Text(contact.postcode) }
                            }
                        }
                    }
                }

                Section {
                    Button("Delete Contact", role: .destructive) {
                        Task {
                            try? await APIService.shared.deleteContact(id: contact.id)
                            await onUpdate()
                            dismiss()
                        }
                    }
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }
}

// MARK: - Add Contact

struct AddContactView: View {
    let onCreated: () async -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var knownAs = ""
    @State private var firstName = ""
    @State private var lastName = ""
    @State private var email = ""
    @State private var phone = ""
    @State private var dob = ""
    @State private var isSaving = false

    var body: some View {
        NavigationStack {
            Form {
                Section("Name") {
                    TextField("Known As (nickname)", text: $knownAs)
                    TextField("First Name", text: $firstName)
                    TextField("Last Name", text: $lastName)
                }

                Section("Details") {
                    TextField("Phone", text: $phone)
                        .keyboardType(.phonePad)
                    TextField("Email", text: $email)
                        .keyboardType(.emailAddress)
                        .autocapitalization(.none)
                    TextField("Date of Birth (YYYY-MM-DD)", text: $dob)
                }
            }
            .navigationTitle("New Contact")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }
                        .disabled(knownAs.isEmpty || firstName.isEmpty || lastName.isEmpty || isSaving)
                }
            }
        }
    }

    private func save() async {
        isSaving = true
        let request = CreateContactRequest(
            knownAs: knownAs,
            firstName: firstName,
            lastName: lastName,
            email: email.isEmpty ? nil : email,
            phone: phone.isEmpty ? nil : phone,
            dob: dob.isEmpty ? nil : dob,
            streetAddress: nil,
            city: nil,
            postcode: nil
        )

        do {
            _ = try await APIService.shared.createContact(request)
            await onCreated()
            dismiss()
        } catch {
            print("Create contact error: \(error)")
        }
        isSaving = false
    }
}
